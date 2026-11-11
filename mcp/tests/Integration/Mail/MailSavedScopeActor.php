<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\PolicyUsers;
use OCA\Mcp\Access\ResourceScope\{ResourceScopeWriter,ScopeActivation,ScopeMutations,NativeResourceEnumerator};
use OCA\Mcp\Db\{ToolGrantsMapper,ResourceScopeMapper,PolicyStateMapper};
use OCA\Mcp\OAuth\{ConnectionContext,ConnectionToolsStore,TokenHasher,OAuthStore,TokenService};
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Modules\Mail\Access\MailAccessGuard;
use OCA\Mcp\Modules\Mail\Integration\MailContentReader;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,ToolPermissionFixture,ToolReply};
use OCP\{Server,IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Synthetic users/resources only. Test writer opens its own activation barrier; shipping composition stays closed. */
final class MailSavedScopeActor {
    private static function writer():ResourceScopeWriter {return new ResourceScopeWriter(Server::get(PolicyTransaction::class),Server::get(PolicyUsers::class),new ScopeActivation(['mail'=>true,'files'=>true]),Server::get(NativeResourceEnumerator::class),Server::get(ScopeMutations::class));}
    public static function scope(string $uid,?array $items):void {self::writer()->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'],'admin',$uid,'mail',$items);}
    private static function data(ToolReply $r):array {if(!$r->ok())throw new \RuntimeException('Synthetic scoped HTTP call unexpectedly refused.');return $r->structured()!==[]?$r->structured():$r->json();}
    private static function count(string $table,string $column,int $id):int {$q=Server::get(IDBConnection::class)->getQueryBuilder();$r=$q->selectAlias($q->func()->count('*'),'n')->from($table)->where($q->expr()->eq($column,$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}}
    public static function run(string $uid,int $message,int $account,int $box,string $mode):array {
        $tools=array_keys(MailToolCatalog::definitions());$config=Server::get(IConfig::class);$policy=Server::get(GrantPolicy::class);$db=Server::get(IDBConnection::class);$snapshot=ToolPermissionFixture::capture([$uid],array_merge([ToolGrantsMapper::SERVICE],$tools));$oldScope=Server::get(ResourceScopeMapper::class)->forModule($uid,'mail');$global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__missing__');$legacy=[];foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY] as $key)$legacy[$key]=$config->getUserValue($uid,'mcp',$key,'__missing__');$connection=null;$drafts=[];$extraBoxes=[];$extraMessages=[];
        try {
            $policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);$mapper=Server::get(ToolGrantsMapper::class);
            Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($mapper,$uid,$tools):bool {foreach(array_merge([ToolGrantsMapper::SERVICE],$tools) as $tool)$mapper->set('user',$uid,$tool,'allow');return true;});
            $credential=LoginFactory::bearer($uid);$row=Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash(substr($credential->authorization(),7)));$connection=(int)$row['id'];$store=Server::get(ConnectionToolsStore::class);$selection=$store->get($uid,$connection);$store->save($uid,$connection,$tools,$selection['revision']);$context=Server::get(ConnectionContext::class);$context->set($connection);$context->setSelection($tools);
            self::scope($uid,['account:'.$account]);
            $q=$db->getQueryBuilder();$q->update('mail_messages')->set('flag_draft',$q->createNamedParameter(true,IQueryBuilder::PARAM_BOOL))->set('thread_root_id',$q->createNamedParameter('scope-thread'))->where($q->expr()->eq('id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT)))->executeStatement();
            if($mode!=='service_http_scopes')return self::nativeRevocation($uid,$message,$account,$box,$mode);
            $client=new McpClient($credential);$args=['account_id'=>$account,'subject'=>'Scoped synthetic local','body_plain'=>'Synthetic scoped draft'];$plan=self::data($client->tool('mail_write_draft',$args));$confirmed=$args+['confirm'=>true,'operation_id'=>$plan['operation_id'],'plan_state'=>$plan['plan_state']];$written=self::data($client->tool('mail_write_draft',$confirmed));$draft=(int)$written['draft']['id'];$drafts[]=$draft;
            $b=new \OCA\Mail\Db\Mailbox();$b->setAccountId($account);$b->setName('Scope hidden');$b->setNameHash(hash('sha256','Scope hidden'));$b->setSelectable(true);$b->setSyncInBackground(false);$b->setAttributes('[]');$b->setSpecialUse('[]');$b->setMessages(1);$b->setUnseen(1);$b->setDelimiter('/');foreach(['setSyncNewToken','setSyncChangedToken','setSyncVanishedToken'] as $set)$b->$set(base64_encode('V42,U9,H1,M1'));
            $b=Server::get('OCA\\Mail\\Db\\MailboxMapper')->insert($b);$hidden=(int)$b->getId();$extraBoxes[]=$hidden;
            $original=Server::get('OCA\\Mail\\Service\\MailManager')->getMessage($uid,$message);$m=new \OCA\Mail\Db\Message();$m->setMailboxId($hidden);$m->setUid(8);$m->setMessageId('hidden-scope@example.test');$m->setThreadRootId('scope-thread');$m->setSubject('SCOPE-HIDDEN-CANARY');$m->setSentAt(time());$m->setUpdatedAt(time());$m->setStructureAnalyzed(false);foreach(['Answered','Deleted','Draft','Flagged','Seen','Forwarded','Junk','Notjunk','Attachments','Mdnsent'] as $flag)$m->{'setFlag'.$flag}(false);$m->setEncrypted(false);$m->setTagCacheGeneration('42');$m->setTagCacheProfile($original->getTagCacheProfile());
            $m=Server::get('OCA\\Mail\\Db\\MessageMapper')->insert($m);$hiddenMessage=(int)$m->getId();$extraMessages[]=$hiddenMessage;
            self::scope($uid,['mailbox:'.$box]);
            $cached=self::data($client->tool('mail_search_drafts',['account_id'=>$account,'limit'=>1]));$metadata=self::data($client->tool('mail_read_message_metadata',['message_ids'=>[$message]]));$thread=self::data($client->tool('mail_read_thread',['message_id'=>$message,'limit'=>1]));$boxes=self::data($client->tool('mail_list_mailboxes',['account_id'=>$account]));
            $denials=[
                'mail_read_account_settings'=>['account_id'=>$account],'mail_read_connection'=>['account_id'=>$account],'mail_list_aliases'=>['account_id'=>$account],'mail_read_signature'=>['account_id'=>$account],
                'mail_list_tags'=>[],'mail_create_tag'=>['name'=>'Synthetic','color'=>'#ff0000'],'mail_read_drafts'=>['draft_id'=>$draft], 'mail_write_draft'=>$args,
                'mail_delete_draft'=>['draft_id'=>$draft],'mail_send'=>['draft_id'=>$draft], 'mail_test_connection'=>['account_id'=>$account],
                'mail_import_draft'=>['message_id'=>$message,'target_account_id'=>$account], 'mail_read_message_metadata'=>['message_ids'=>[$message,$hiddenMessage]],
                'mail_move_message'=>['account_id'=>$account,'message_id'=>$message,'destination_mailbox_id'=>$hidden],
                'mail_read_message'=>['message_id'=>$hiddenMessage], 'mail_index_messages'=>['message_ids'=>[$message,$hiddenMessage]], 'mail_search_indexed_body'=>['mailbox_ids'=>[$box,$hidden],'query'=>'text'],
                'mail_export_message'=>['message_id'=>$hiddenMessage,'path'=>'/scope-hidden.eml'], 'mail_save_attachment'=>['message_id'=>$hiddenMessage,'attachment_id'=>'2','path'=>'/scope-hidden.bin'],
            ];$refused=[];foreach($denials as $tool=>$a)$refused[$tool]=!$client->tool($tool,$a)->ok();
            $listDrafts=self::data($client->tool('mail_read_drafts',[]));$outbox=self::data($client->tool('mail_read_outbox',[]));$statusDenied=!$client->tool('mail_get_operation',['operation_id'=>$plan['operation_id']])->ok();$replayDenied=!$client->tool('mail_write_draft',$confirmed)->ok();
            $operations=self::data($client->tool('mail_list_operations',[]));$filtered=!str_contains(json_encode($operations),$plan['operation_id']);
            self::scope($uid,['account:'.$account]);$statusAllowed=$client->tool('mail_get_operation',['operation_id'=>$plan['operation_id']])->ok();
            $native=Server::get('OCA\\Mail\\Service\\DraftsService');$native->deleteMessage($uid,$native->getMessage($draft,$uid));$drafts=[];
            $historical=self::data($client->tool('mail_write_draft',$confirmed));$deletedHistorical=($historical['operation']['replayed']??false)&&($historical['operation']['state']??null)==='succeeded';
            self::scope($uid,[]);$emptyDrafts=self::data($client->tool('mail_read_drafts',[]));$emptyOutbox=self::data($client->tool('mail_read_outbox',[]));$emptyAccounts=self::data($client->tool('mail_list_accounts',[]));
            return ['local_write'=>($written['operation']['state']??null)==='succeeded','remote_folder_read'=>array_column($cached['items'],'message_id')===[$message]&&array_column($metadata['items'],'id')===[$message],
                'no_hidden_thread'=>!str_contains(json_encode($thread),'SCOPE-HIDDEN-CANARY'),'no_hidden_folders'=>!str_contains(json_encode($boxes),'Scope hidden'),'denials'=>$refused,
                'parameterless_local_filtered'=>$listDrafts['drafts']===[]&&$outbox['items']===[]&&$outbox['next_before_id']===null,'history_filtered'=>$statusDenied&&$replayDenied&&$filtered,'history_allowed'=>$statusAllowed,'deleted_history'=>$deletedHistorical,
                'empty_filtered'=>$emptyDrafts['drafts']===[]&&$emptyOutbox['items']===[]&&!str_contains(json_encode($emptyAccounts),'Synthetic content'),'private_refs'=>!str_contains(json_encode([$written,$historical]),'authorization')];
        }finally{
            self::scope($uid,$oldScope['items']??null);
            foreach($drafts as $draft){$service=Server::get('OCA\\Mail\\Service\\DraftsService');$service->deleteMessage($uid,$service->getMessage($draft,$uid));}
            foreach(['mail_messages'=>$extraMessages,'mail_mailboxes'=>$extraBoxes] as $table=>$ids)foreach($ids as $id){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->executeStatement();}
            if($connection!==null){$q=$db->getQueryBuilder();$q->delete('mcp_operations')->where($q->expr()->eq('scope_key',$q->createNamedParameter(hash('sha256',$uid."\0".$connection))))->andWhere($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))->executeStatement();Server::get(TokenService::class)->revokeGrant($connection,$uid);}
            Server::get(ConnectionContext::class)->clear();ToolPermissionFixture::restore($snapshot);if($global==='__missing__')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);foreach($legacy as $key=>$v){if($v==='__missing__')$config->deleteUserValue($uid,'mcp',$key);else $config->setUserValue($uid,'mcp',$key,$v);}Server::get(\OCP\Config\IUserConfig::class)->clearCache($uid,false);
        }
    }
    private static function nativeRevocation(string $uid,int $message,int $account,int $box,string $mode):array {
        $copy=str_contains($mode,'copy');$tool=$copy?'mail_import_draft':'mail_index_messages';$service=Server::get($copy?'OCA\\Mail\\Service\\GuardedDraftImport':'OCA\\Mail\\Service\\GuardedBodyIndex');$reader=Server::get(MailContentReader::class);$base=$reader->featureFence($uid,$tool,static function(array $refs):void {});$preview=$copy?$service->preview($uid,$message,$account,null,$base):$service->preview($uid,[$message],$base);$changed=false;$denied=false;$before=self::count('mail_local_messages','account_id',$account);
        $change=function()use($uid,$mode,$tool,&$changed):void {
            if($changed)return;$changed=true;
            if(str_ends_with($mode,'_scope'))self::scope($uid,[]);
            else Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($uid,$tool):bool {Server::get(ToolGrantsMapper::class)->set('user',$uid,$tool,'deny');return true;});
        };
        $fence=new class($base,$change){public function __construct(private object $base,private \Closure $change){}public function authorize(string $uid,int $a,int $b,int $m):void {$this->base->authorize($uid,$a,$b,$m);}public function authorizeHistorical(string $uid,int $a,int $b,int $m):void {$this->base->authorizeHistorical($uid,$a,$b,$m);}public function checkpoint(array $refs):void {if(isset($refs['content_source'])||isset($refs['content_sources']))($this->change)();}};
        Server::get(GrantPolicy::class)->within($uid,function()use($uid,$account,$copy,$service,$preview,$fence,&$denied):void {
            if(!Server::get(MailAccessGuard::class)->accountAllowed($uid,$account))throw new \RuntimeException('Initial policy did not allow source.');
            try{if($copy)$service->copy($uid,$preview,bin2hex(random_bytes(32)),$fence);else $service->index($uid,$preview,$fence);}catch(\Throwable){$denied=true;}
        });
        return ['prepared'=>true,'revoked'=>$changed,'refused'=>$denied,'no_local_effect'=>self::count('mail_local_messages','account_id',$account)===$before,'no_index_effect'=>self::count('mail_mcp_body_index','message_id',$message)===0,'no_copy_reservation'=>self::count('mail_mcp_draft_copies','target_account_id',$account)===0];
    }
}
