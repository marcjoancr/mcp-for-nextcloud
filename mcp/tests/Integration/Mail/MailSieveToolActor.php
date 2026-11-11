<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\OAuth\{ConnectionContext,ConnectionToolsStore,TokenService,TokenHasher,OAuthStore};
use OCA\Mcp\Tests\Integration\Support\{ToolPermissionFixture,LoginFactory,McpClient};
use OCP\{Server,IDBConnection,IConfig};
final class MailSieveToolActor {
    private const TOOLS=['mail_read_filters','mail_write_filters','mail_read_absence','mail_write_absence','mail_disable_absence','mail_read_sieve_status','mail_transmit_filters','mail_enable_absence','mail_get_operation'];
    private static function call(string $uid,string $tool,array $args):array {
        try{$r=Server::get(GrantPolicy::class)->within($uid,fn()=>Server::get(ToolRegistry::class)->call($tool,$args,$uid));if($r['isError']??false)return ['error'=>true];return $r['structuredContent']??json_decode($r['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){return ['error'=>true];}
    }
    public static function run(string $uid,int $account):array {
        SieveFixtureBoundary::install();$config=Server::get(IConfig::class);$db=Server::get(IDBConnection::class);$context=Server::get(ConnectionContext::class);$connection=null;$id=null;$snapshot=ToolPermissionFixture::capture([$uid],array_merge([ToolGrantsMapper::SERVICE],self::TOOLS));$scope=Server::get(\OCA\Mcp\Db\ResourceScopeMapper::class)->forModule($uid,'mail');$global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__absent');$legacy=[];foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY]as$key)$legacy[$key]=$config->getUserValue($uid,'mcp',$key,'__absent');
        Server::get(GrantPolicy::class)->setGlobalEnabled(true);Server::get(GrantPolicy::class)->setEligible($uid,true);Server::get(GrantPolicy::class)->setConnected($uid,true);
        Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($uid):bool{foreach(array_merge([ToolGrantsMapper::SERVICE],self::TOOLS)as$tool)Server::get(ToolGrantsMapper::class)->set('user',$uid,$tool,'allow');return true;});MailSavedScopeActor::scope($uid,['account:'.$account]);
        try{
            $credential=LoginFactory::bearer($uid);$row=Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash(substr($credential->authorization(),7)));$connection=(int)$row['id'];$selection=Server::get(ConnectionToolsStore::class);$prior=$selection->get($uid,$connection);$selection->save($uid,$connection,self::TOOLS,$prior['revision']);$context->set($connection);$context->setSelection(self::TOOLS);$args=['account_id'=>$account,'filters'=>MailSieveActor::filter()];
            $out=['fpm_unqualified_refused'=>!(new McpClient($credential))->tool('mail_write_filters',$args)->ok()];$p=self::call($uid,'mail_write_filters',$args);if(!isset($p['plan_state']))throw new \RuntimeException('Sieve tool preview unavailable.');$confirm=$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];$accepted=self::call($uid,'mail_write_filters',$confirm);$read=self::call($uid,'mail_get_operation',['operation_id'=>$p['operation_id']]);$id=$read['sieve']['id']??null;if(!is_string($id))throw new \RuntimeException('Sieve tool receipt unavailable.');
            $out['completed']=($accepted['sieve']['state']??null)==='completed';$out['replayed']=self::call($uid,'mail_write_filters',$confirm)['operation']['replayed']??false;$out['status']=self::call($uid,'mail_read_sieve_status',['sieve_id'=>$id])['sieve']['observation']??null;$out['private_origin_hidden']=!preg_match('/actor_incarnation|authorization|operationKeyHash|SYNTHETIC_PRIVATE|candidate_hash|program/',json_encode([$accepted,$read]));
            $out['gate_denied']=self::call($uid,'mail_transmit_filters',$args)['error']??false;
            $original=$context->current();$context->set($connection+1000000);$context->setSelection(self::TOOLS);$out['other_connection_denied']=self::call($uid,'mail_read_sieve_status',['sieve_id'=>$id])['error']??false;$context->set($original);$context->setSelection(self::TOOLS);
            MailSavedScopeActor::scope($uid,[]);$out['scope_status_denied']=self::call($uid,'mail_read_sieve_status',['sieve_id'=>$id])['error']??false;$out['scope_replay_denied']=self::call($uid,'mail_write_filters',$confirm)['error']??false;MailSavedScopeActor::scope($uid,['account:'.$account]);
            $out['scope_restored']=self::call($uid,'mail_read_sieve_status',['sieve_id'=>$id])['sieve']['observation']??null;
            Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($uid):bool{Server::get(ToolGrantsMapper::class)->set('user',$uid,'mail_write_filters','deny');return true;});$out['source_grant_denied']=self::call($uid,'mail_read_sieve_status',['sieve_id'=>$id])['error']??false;
            return $out;
        }finally{
            MailSavedScopeActor::scope($uid,$scope['items']??null);if($id!==null){$q=$db->getQueryBuilder();$q->delete('mail_mcp_sieve')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();}
            if($connection!==null){$scopeKey=hash('sha256',$uid."\0".$connection);foreach(['mcp_operation_origins','mcp_operations']as$table){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('scope_key',$q->createNamedParameter($scopeKey)))->executeStatement();}Server::get(TokenService::class)->revokeGrant($connection,$uid);}
            $context->clear();ToolPermissionFixture::restore($snapshot);if($global==='__absent')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);Server::get(\OCP\IAppConfig::class)->clearCache(false);foreach($legacy as$key=>$value){if($value==='__absent')$config->deleteUserValue($uid,'mcp',$key);else $config->setUserValue($uid,'mcp',$key,$value);}Server::get(\OCP\Config\IUserConfig::class)->clearCache($uid,false);
        }
    }
}
