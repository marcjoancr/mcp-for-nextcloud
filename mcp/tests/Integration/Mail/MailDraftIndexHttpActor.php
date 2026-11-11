<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\OAuth\{ConnectionContext,TokenHasher,OAuthStore,TokenService,ConnectionToolsStore};
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,ToolPermissionFixture,ToolReply};
use OCP\{Server,IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
final class MailDraftIndexHttpActor {
    private static function data(ToolReply $r):array {if(!$r->ok())throw new \RuntimeException('Synthetic feature HTTP call refused: '.$r->describe());return $r->structured()!==[]?$r->structured():$r->json();}
    public static function run(string $uid,int $message,int $account,int $box):array {
        $tools=['mail_search_drafts','mail_import_draft','mail_index_messages','mail_search_indexed_body','mail_get_operation'];$denied=['mail_read_message','mail_read_drafts','mail_write_draft','mail_export_message'];$config=Server::get(IConfig::class);$policy=Server::get(GrantPolicy::class);$db=Server::get(IDBConnection::class);$snapshot=ToolPermissionFixture::capture([$uid],array_merge([ToolGrantsMapper::SERVICE],$tools,$denied));$global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__missing__');$legacy=[];foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY] as $k)$legacy[$k]=$config->getUserValue($uid,'mcp',$k,'__missing__');$connection=null;$draft=null;
        try{
            $q=$db->getQueryBuilder();$q->update('mail_messages')->set('flag_draft',$q->createNamedParameter(true,IQueryBuilder::PARAM_BOOL))->where($q->expr()->eq('id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT)))->executeStatement();
            $policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);$mapper=Server::get(ToolGrantsMapper::class);
            Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($uid,$mapper,$tools,$denied):bool {foreach(array_merge([ToolGrantsMapper::SERVICE],$tools) as $tool)$mapper->set(ToolGrantsMapper::USER,$uid,$tool,'allow');foreach($denied as $tool)$mapper->set(ToolGrantsMapper::USER,$uid,$tool,'deny');return true;});
            $credential=LoginFactory::bearer($uid);$row=Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash(substr($credential->authorization(),7)));$connection=(int)$row['id'];$store=Server::get(ConnectionToolsStore::class);$selection=$store->get($uid,$connection);$store->save($uid,$connection,$tools,$selection['revision']);$client=new McpClient($credential);
            $independent=!$client->tool('mail_read_message',['message_id'=>$message])->ok();$cached=self::data($client->tool('mail_search_drafts',['account_id'=>$account,'limit'=>1]));
            $args=['message_id'=>$message,'target_account_id'=>$account];$preview=self::data($client->tool('mail_import_draft',$args));$confirmed=$args+['confirm'=>true,'plan_state'=>$preview['plan_state'],'operation_id'=>$preview['operation_id']];$copy=self::data($client->tool('mail_import_draft',$confirmed));$draft=$copy['draft_id']??null;
            $indexArgs=['message_ids'=>[$message]];$indexPlan=self::data($client->tool('mail_index_messages',$indexArgs));$index=self::data($client->tool('mail_index_messages',$indexArgs+['confirm'=>true,'plan_state'=>$indexPlan['plan_state'],'operation_id'=>$indexPlan['operation_id']]));$search=self::data($client->tool('mail_search_indexed_body',['mailbox_ids'=>[$box],'query'=>'text','limit'=>1]));
            $q=$db->getQueryBuilder();$r=$q->select('id')->from('mail_messages')->where($q->expr()->eq('id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT)))->executeQuery();try{$retained=$r->fetch()!==false;}finally{$r->closeCursor();}
            $q=$db->getQueryBuilder();$q->update('mcp_operations')->set('state',$q->createNamedParameter('uncertain'))->where($q->expr()->eq('operation_id',$q->createNamedParameter($preview['operation_id'])))->andWhere($q->expr()->eq('scope_key',$q->createNamedParameter(hash('sha256',$uid."\0".$connection))))->executeStatement();
            $q=$db->getQueryBuilder();$q->delete('mail_messages')->where($q->expr()->eq('id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT)))->executeStatement();$q=$db->getQueryBuilder();$r=$q->select('message_id')->from('mail_mcp_body_index')->where($q->expr()->eq('message_id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT)))->executeQuery();try{$indexErased=$r->fetch()===false;}finally{$r->closeCursor();}$replay=self::data($client->tool('mail_import_draft',$confirmed));$status=self::data($client->tool('mail_get_operation',['operation_id'=>$preview['operation_id']]));
            $selection=$store->get($uid,$connection);$store->save($uid,$connection,array_values(array_diff($tools,['mail_import_draft'])),$selection['revision']);$revoked=!$client->tool('mail_import_draft',$confirmed)->ok()&&!$client->tool('mail_get_operation',['operation_id'=>$preview['operation_id']])->ok();
            return ['index_erased'=>$indexErased,'independent_grants'=>$independent,'cached_found'=>array_column($cached['items'],'message_id')===[$message],'copy_succeeded'=>($copy['operation']['state']??null)==='succeeded'&&is_int($draft),'source_retained'=>$retained,'index_succeeded'=>($index['operation']['state']??null)==='succeeded','search_found'=>array_column($search['items'],'message_id')===[$message],'replayed_after_source_deleted'=>($replay['operation']['replayed']??false)&&($replay['operation']['resource_refs']['draft_id']??null)===$draft,'status_succeeded'=>($status['operation']['state']??null)==='uncertain'&&($status['operation']['resource_refs']['draft_id']??null)===$draft,'revoked'=>$revoked,'private_receipt'=>!str_contains(json_encode([$copy,$replay,$status]),'content_base64')];
        }finally{
            if(is_int($draft)){ $service=Server::get('OCA\\Mail\\Service\\DraftsService');$service->deleteMessage($uid,$service->getMessage($draft,$uid));}
            if($connection!==null){$q=$db->getQueryBuilder();$q->delete('mcp_operations')->where($q->expr()->eq('scope_key',$q->createNamedParameter(hash('sha256',$uid."\0".$connection))))->andWhere($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))->executeStatement();Server::get(TokenService::class)->revokeGrant($connection,$uid);}
            Server::get(ConnectionContext::class)->clear();ToolPermissionFixture::restore($snapshot);if($global==='__missing__')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);foreach($legacy as $k=>$v){if($v==='__missing__')$config->deleteUserValue($uid,'mcp',$k);else $config->setUserValue($uid,'mcp',$k,$v);}Server::get(\OCP\Config\IUserConfig::class)->clearCache($uid,false);
        }
    }
}
