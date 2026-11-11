<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\{ToolGrantsMapper,OperationsMapper,ResourceScopeMapper};
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\OAuth\{ConnectionContext,ConnectionToolsStore,TokenService,TokenHasher,OAuthStore};
use OCA\Mcp\Tests\Integration\Support\{ToolPermissionFixture,LoginFactory,McpClient};
use OCP\{Server,IDBConnection,IConfig};
final class MailSignatureModeToolActor {
    private const TOOLS=['mail_write_signature_mode','mail_get_operation','mail_list_operations','mail_reconcile_operation'];
    private static function call(string $uid,string $tool,array $args):array {
        try{$r=Server::get(GrantPolicy::class)->within($uid,fn()=>Server::get(ToolRegistry::class)->call($tool,$args,$uid));if($r['isError']??false)return ['error'=>true];return $r['structuredContent']??json_decode($r['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){return ['error'=>true];}
    }
    public static function run(string $uid,string $scenario):array {
        SignatureModeFixtureBoundary::install();$config=Server::get(IConfig::class);$db=Server::get(IDBConnection::class);$context=Server::get(ConnectionContext::class);$connection=null;$snapshot=ToolPermissionFixture::capture([$uid],array_merge([ToolGrantsMapper::SERVICE],self::TOOLS));$scope=Server::get(ResourceScopeMapper::class)->forModule($uid,'mail');$global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__absent');$legacy=[];foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY]as$key)$legacy[$key]=$config->getUserValue($uid,'mcp',$key,'__absent');
        $accounts=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$account=$accounts->findByUserId($uid)[0];$id=(int)$account->getId();$mode=$account->getSignatureMode();$aliases=Server::get('OCA\\Mail\\Db\\AliasMapper');$alias=new \OCA\Mail\Db\Alias();$alias->setAccountId($id);$alias->setAlias('signature-tool@example.test');$alias->setName('Signature mode tool');$alias->setSignature("<p>MODE_PRIVATE_SIGNATURE\n&amp;</p>");$alias->setSignatureMode(0);$alias=$aliases->insert($alias);$aid=(int)$alias->getId();
        Server::get(GrantPolicy::class)->setGlobalEnabled(true);Server::get(GrantPolicy::class)->setEligible($uid,true);Server::get(GrantPolicy::class)->setConnected($uid,true);Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($uid):bool{foreach(array_merge([ToolGrantsMapper::SERVICE],self::TOOLS)as$tool)Server::get(ToolGrantsMapper::class)->set('user',$uid,$tool,'allow');return true;});MailSavedScopeActor::scope($uid,['account:'.$id]);
        try{
            $credential=LoginFactory::bearer($uid);$row=Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash(substr($credential->authorization(),7)));$connection=(int)$row['id'];$selection=Server::get(ConnectionToolsStore::class);$prior=$selection->get($uid,$connection);$selection->save($uid,$connection,self::TOOLS,$prior['revision']);$context->set($connection);$context->setSelection(self::TOOLS);
            $args=['account_id'=>$id,'alias_id'=>$aid,'signature_mode'=>1];$out=['fpm_shipping_refused'=>!(new McpClient($credential))->tool('mail_write_signature_mode',$args)->ok()];$p=self::call($uid,'mail_write_signature_mode',$args);if(!isset($p['plan_state']))throw new \RuntimeException('Signature preview unavailable.');$confirm=$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];
            if($scenario==='lost_finish'){
                $journal=Server::get(OperationJournal::class);$prop=new \ReflectionProperty($journal,'store');$original=$prop->getValue($journal);$prop->setValue($journal,new SignatureModeCheckpointStore($original,static fn()=>null,'lost_finish'));
                try{$lost=self::call($uid,'mail_write_signature_mode',$confirm);}finally{$prop->setValue($journal,$original);}
                $before=MailSignatureModeActor::row('mail_aliases',$aid);$status=self::call($uid,'mail_get_operation',['operation_id'=>$p['operation_id']]);$out['lost_ack_retained']=($lost['error']??false)&&($status['operation']['state']??'')==='running'&&($status['operation']['resource_refs']['signature_mode']??null)===1;
                $out['lost_replay_no_effect']=self::call($uid,'mail_write_signature_mode',$confirm)['operation']['replayed']??false;
                $args=['account_id'=>$id,'target_operation_id'=>$p['operation_id']];$review=self::call($uid,'mail_reconcile_operation',$args);$out['completion_proven']=($review['observed']['proof']??null)==='atomic_local_completion';
                $settled=self::call($uid,'mail_reconcile_operation',$args+['confirm'=>true,'plan_state'=>$review['plan_state']??'','operation_id'=>$review['operation_id']??'']);$out['reconciled']=($settled['recorded_state']??null)==='succeeded'&&($settled['native_write_repeated']??true)===false;
                $out['recovery_preserved']=MailSignatureModeActor::row('mail_aliases',$aid)===$before;return $out;
            }
            if($scenario!=='normal'){
                [$stage,$kind]=explode(':',$scenario);$state=(object)['reached'=>false,'effect_seen'=>false];$journal=Server::get(OperationJournal::class);$prop=new \ReflectionProperty($journal,'store');$original=$prop->getValue($journal);
                $change=function()use($uid,$kind,$state,$aid):void{$state->reached=true;$state->effect_seen=(int)MailSignatureModeActor::row('mail_aliases',$aid)['signature_mode']===1;match($kind){'scope'=>Server::get(ResourceScopeMapper::class)->replace($uid,'mail',[],Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision']),'grant'=>Server::get(ToolGrantsMapper::class)->set('user',$uid,'mail_write_signature_mode','deny'),'actor'=>Server::get(\OCA\Mcp\Db\ActorIncarnationsMapper::class)->rotate($uid)};};
                $prop->setValue($journal,new SignatureModeCheckpointStore($original,$change,$stage));
                $before=MailSignatureModeActor::row('mail_aliases',$aid);
                try{$result=self::call($uid,'mail_write_signature_mode',$confirm);}finally{$prop->setValue($journal,$original);}
                $out+=['boundary_reached'=>$state->reached,'effect_seen'=>$state->effect_seen,'refused'=>($result['error']??false)||($result['operation']['state']??'')==='failed_before_effect','rolled_back'=>MailSignatureModeActor::row('mail_aliases',$aid)===$before];return $out;
            }
            $out['preview_modes']=$p['before']['signature_mode']===0&&$p['after']['signature_mode']===1;
            $before=MailSignatureModeActor::row('mail_aliases',$aid);$accepted=self::call($uid,'mail_write_signature_mode',$confirm);$after=MailSignatureModeActor::row('mail_aliases',$aid);unset($before['signature_mode'],$after['signature_mode']);$out['alias_preserved']=$before===$after;
            $out['completed']=($accepted['operation']['state']??null)==='succeeded'&&($accepted['identity']['signature_mode']??null)===1;$read=self::call($uid,'mail_get_operation',['operation_id'=>$p['operation_id']]);$out['status']=($read['operation']['resource_refs']['signature_mode']??null)===1;$out['replayed']=self::call($uid,'mail_write_signature_mode',$confirm)['operation']['replayed']??false;
            $out['private_fields_hidden']=!preg_match('/MODE_PRIVATE_SIGNATURE|actor_incarnation|authorization|inboundPassword/',json_encode([$p,$accepted,$read]));
            $aargs=['account_id'=>$id,'signature_mode'=>1-$mode];$ap=self::call($uid,'mail_write_signature_mode',$aargs);$before=MailSignatureModeActor::row('mail_accounts',$id);$ar=self::call($uid,'mail_write_signature_mode',$aargs+['confirm'=>true,'plan_state'=>$ap['plan_state'],'operation_id'=>$ap['operation_id']]);$after=MailSignatureModeActor::row('mail_accounts',$id);unset($before['signature_mode'],$after['signature_mode']);$out['account_preserved']=$before===$after&&($ar['operation']['state']??null)==='succeeded';
            $stale=self::call($uid,'mail_write_signature_mode',$args);$a=$aliases->find($aid,$uid);$a->setName('Changed after approval');$aliases->update($a);$refused=self::call($uid,'mail_write_signature_mode',$args+['confirm'=>true,'plan_state'=>$stale['plan_state'],'operation_id'=>$stale['operation_id']]);$out['stale_refused']=!isset($refused['identity'])&&($refused['operation']['state']??null)!=='succeeded';
            $context->set($connection+1000000);$context->setSelection(self::TOOLS);$out['other_connection_denied']=self::call($uid,'mail_get_operation',['operation_id'=>$p['operation_id']])['error']??false;$context->set($connection);$context->setSelection(self::TOOLS);
            MailSavedScopeActor::scope($uid,[]);$out['scope_status_denied']=self::call($uid,'mail_get_operation',['operation_id'=>$p['operation_id']])['error']??false;$out['scope_replay_denied']=self::call($uid,'mail_write_signature_mode',$confirm)['error']??false;MailSavedScopeActor::scope($uid,['account:'.$id]);
            Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($uid):bool{Server::get(ToolGrantsMapper::class)->set('user',$uid,'mail_write_signature_mode','deny');return true;});$out['source_grant_denied']=self::call($uid,'mail_get_operation',['operation_id'=>$p['operation_id']])['error']??false;return $out;
        }finally{
            $aliases->delete($aliases->find($aid,$uid));$a=$accounts->find($uid,$id);$a->setSignatureMode($mode);$accounts->update($a);MailSavedScopeActor::scope($uid,$scope['items']??null);
            if($connection!==null){$scopeKey=hash('sha256',$uid."\0".$connection);foreach(['mcp_operation_origins','mcp_operations']as$table){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('scope_key',$q->createNamedParameter($scopeKey)))->executeStatement();}Server::get(TokenService::class)->revokeGrant($connection,$uid);}
            $context->clear();ToolPermissionFixture::restore($snapshot);if($global==='__absent')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);Server::get(\OCP\IAppConfig::class)->clearCache(false);foreach($legacy as$key=>$value){if($value==='__absent')$config->deleteUserValue($uid,'mcp',$key);else $config->setUserValue($uid,'mcp',$key,$value);}Server::get(\OCP\Config\IUserConfig::class)->clearCache($uid,false);
        }
    }
}
