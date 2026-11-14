<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Comments;
use OCP\Server;
use OCA\Forms\Mcp\Service\{FreshCore,ScopedServices,OriginContext};
final class CommentsRuntime {
    /** Does not resolve ICommentsManager or Guard, including during global factory construction. */
    public static function available(): bool {
        try {
            if (!\OCA\Forms\Mcp\SourceIdentity::verify() || !\OCA\Forms\Mcp\Service\SharingIdentity::verify()) {return false;}
            $db=Server::get(\OCP\IDBConnection::class);
            if (PHP_INT_SIZE!==8 || array_slice(Server::get(\OCP\ServerVersion::class)->getVersion(),0,3)!==[35,0,2]
                || !in_array($db->getDatabaseProvider(true),['postgres','mariadb'],true)
                || !empty(Server::get(\OCP\IConfig::class)->getSystemValue('dbreplica',[]))) {return false;}
            if ($db->executeQuery('SELECT version FROM *PREFIX*migrations WHERE app = ? AND version = ?',['forms','050402Date20261113070000'])->fetchOne()===false) {return false;}
            $enabled=$db->executeQuery('SELECT configvalue FROM *PREFIX*appconfig WHERE appid = ? AND configkey = ?',['forms','enabled'])->fetchOne();
            if (!is_string($enabled) || $enabled==='' || $enabled==='no') {return false;}
            $sources=json_decode(file_get_contents(__DIR__.'/Sources.json'),true,32,JSON_THROW_ON_ERROR);
            foreach ($sources as $path=>$hash) {if (!is_file(\OC::$SERVERROOT.'/'.$path) || !hash_equals($hash,hash_file('sha256',\OC::$SERVERROOT.'/'.$path))) {return false;}}
            return true;
        } catch (\Throwable) {return false;}
    }
    /** Publication requires a successful explicit fresh-process activation for these exact artifacts. */
    public static function approved(): bool {
        try {
            $directory=rtrim(\OC::$configDir,'/');
            $path=$directory.'/mcp_forms_comments_install.json';
            $config=Server::get(\OCP\IConfig::class);
            if (!is_file($path) || is_link($path) || !is_writable($path) || !is_writable($directory)
                || $config->getSystemValueBool('config_is_read_only',false)
                || $config->getSystemValue('comments.managerFactory')!=='McpFormsServerExtension\\CommentsFactory') {return false;}
            $metadata=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
            if (($metadata['active']??false)!==true || !hash_equals($metadata['native_manifest']??'',hash_file('sha256',dirname(__DIR__,3).'/mcp-guard-installed.json'))) {return false;}
            foreach (['mcp_forms_comments_bootstrap.php','mcp-forms-comments.config.php'] as $name) {
                if (!is_file($directory.'/'.$name) || is_link($directory.'/'.$name) || !hash_equals($metadata['hashes'][$name]??'',hash_file('sha256',$directory.'/'.$name))) {return false;}
            }
            return true;
        } catch (\Throwable) {return false;}
    }
    public static function actor(): string {
        return Server::get(\OCP\IUserSession::class)->getUser()?->getUID() ?? throw new \RuntimeException('Comment actor is unavailable');
    }
    public static function admit(string $uid,int $formId,bool $deferred=false): array {
        if (!self::available()) {throw new \RuntimeException('Forms comments runtime is unavailable');}
        $core=new FreshCore($uid);
        $core->assertPrincipal(false,OriginContext::active());
        $scope=new ScopedServices($uid,$deferred,false,!OriginContext::active());
        $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($formId);
        if (!$form->getAllowComments() || !$scope->forms()->hasUserAccess($form)) {throw new \RuntimeException('Forms comments are unavailable to this actor');}
        if (OriginContext::active()) {
            OriginContext::assertFresh();
            $verified=OriginContext::current()['verified'];
            if ($verified['uid']!==$uid || (int)($verified['references']['form_id']??0)!==$formId) {
                throw new \RuntimeException('Original comment principal or form changed');
            }
        }
        return ['owner'=>$form->getOwnerId(),'principal'=>self::principal($uid)];
    }
    public static function mutation(string $action,string $commentId,bool $parentMetadata=false): void {
        if (!OriginContext::active()) {return;}
        $context=OriginContext::current();
        $verified=$context['verified'];
        $internalParent=$parentMetadata && $action==='forms_update_comment'
            && in_array($verified['tool'],['forms_add_comment','forms_update_comment'],true);
        if (!$internalParent && $verified['tool']!==$action) {
            throw new \RuntimeException('Original authority does not permit this comment mutation');
        }
        if ($internalParent || $action==='forms_add_comment') {return;}
        foreach ([$context['input']['comment_id']??null,$verified['references']['comment_id']??null] as $approvedId) {
            if ($approvedId!==null && (string)$approvedId!==$commentId) {
                throw new \RuntimeException('Original comment target changed');
            }
        }
    }
    public static function principal(string $uid): string {
        $db=Server::get(\OCP\IDBConnection::class);
        $row=$db->executeQuery('SELECT uid,password FROM *PREFIX*users WHERE uid = ?',[$uid])->fetch();
        if (!$row) {throw new \RuntimeException('Native comment principal is unavailable');}
        return hash_hmac('sha256',json_encode($row,JSON_THROW_ON_ERROR),Server::get(\OCP\IConfig::class)->getSystemValueString('secret'));
    }
    public static function author(\OCP\Comments\IComment $comment,string $uid): void {
        if ($comment->getActorType()!=='users' || $comment->getActorId()!==$uid) {throw new \RuntimeException('Only the native comment author may change this comment');}
    }
}
