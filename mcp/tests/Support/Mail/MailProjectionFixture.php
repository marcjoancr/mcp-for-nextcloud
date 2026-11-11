<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Mail;

use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;
use OCA\Mcp\Modules\Mail\Access\MailFreshAccess;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\OAuth\{ConnectionContext,ConnectionToolsStore,TokenOwnerGate};
use OCA\Mcp\Db\{ToolGrantsMapper,AuthUsersMapper,OAuthClientsMapper};

/** Real policy/selection prerequisites for existing lower-level journal/setup tests. */
trait MailProjectionFixture {
    private function projectionAccess(OperationJournal $journal,ConnectionContext $context):MailFreshAccess {
        $fx=new ScopeFixture($this);$services=$fx->services();$uid='alice';
        $services->grants->setEligible($uid,true);$services->grants->setConnected($uid,true);$services->service->setGlobalEnabled(true);
        $grants=new ToolGrantsMapper($fx->db);
        foreach(MailToolCatalog::definitions()as$name=>$definition){
            $services->grants->setGrant($uid,'mail',$definition['operation'],true);
            $fx->db->beginTransaction();$grants->set('user',$uid,$name,'allow');$fx->db->commit();
        }
        $services->grants->setGrant($uid,'files','create',true);
        $clock=$this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $fx->database->dbal->executeStatement('CREATE TABLE mcp_oauth_tools (user_id TEXT, connection_id INTEGER, tool_names TEXT, tool_variants TEXT, revision INTEGER)');
        $fx->database->dbal->executeStatement('CREATE TABLE mcp_oauth_tokens (id INTEGER, user_id TEXT, client_id TEXT, auth_global_epoch TEXT, auth_gen TEXT, refresh_expires INTEGER)');
        $auth=new AuthUsersMapper($fx->db);$generation=$auth->generations($uid);
        $fx->database->dbal->executeStatement('INSERT INTO mcp_oauth_tokens VALUES (?,?,?,?,?,999999)',[$context->current(),$uid,'synthetic-projection-client',$fx->state->state()['auth_epoch'],$generation['issue_seq']]);
        $owner=$this->createMock(TokenOwnerGate::class);$owner->method('owner')->willReturn($fx->userManager()->get($uid));
        $selections=new ConnectionToolsStore($fx->db,$clock,$owner,$auth,$fx->state,new OAuthClientsMapper($fx->db));
        $fx->database->dbal->executeStatement('INSERT INTO mcp_oauth_tools VALUES (?,?,?,?,1)',[$uid,$context->current(),json_encode(array_keys(MailToolCatalog::definitions()),JSON_THROW_ON_ERROR),'{}']);
        $apps=$this->createMock(\OCP\App\IAppManager::class);$apps->method('isEnabledForUser')->willReturn(true);$apps->method('getAppVersion')->willReturn('99.0.0');$apps->method('getAppPath')->willReturn('/native-mail');
        $guests=$this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);$guests->method('allowsApp')->willReturn(true);
        return new MailFreshAccess($fx->policy($services,$grants),$apps,$fx->userManager(),$guests,$context,$selections,$journal);
    }
    private function projectionAuthorization(OperationJournal $journal):\OCA\Mcp\Modules\Mail\Access\MailOperationAuthorization {
        $access=new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub());
        $native=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailServices::class);
        $native->method('boundedMetadata')->willReturn(new class {public function identities(string $uid,string $kind,array $ids):array {return array_map(static fn(int $id):array=>['id'=>$id,'account_id'=>$id,'mailbox_id'=>0],$ids);}});
        return new \OCA\Mcp\Modules\Mail\Access\MailOperationAuthorization($access,$native,$journal,$this->createMock(\OCA\Mcp\Access\Grants\GrantPolicy::class),$this->createMock(\OCA\Mcp\Access\ResourceScope\FilesScope::class),$this->createMock(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class),$this->createMock(\OCP\Files\IRootFolder::class),new \OCA\Mcp\Modules\Mail\Integration\MailTagVisibility($native,$access),$this->createMock(\OCA\Mcp\Modules\Mail\Access\MailDeliveryOrigin::class));
    }
    private static function projectionRefs():array {return ['v'=>1,'mail'=>[],'global'=>true,'files'=>[],'tools'=>[]];}
    private function projectedEffect(OperationJournal $journal,\Closure $effect):array {
        $journal->recordAuthorization('alice',self::projectionRefs());
        return $effect();
    }
}
