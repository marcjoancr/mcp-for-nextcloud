<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\OAuth\{ConnectionContext,ConnectionToolsStore,TokenService,TokenHasher,OAuthStore};
use OCA\Mcp\Modules\Mail\Transmission\{DeliveryRunner,MailDeliveryOperations};
use OCA\Mcp\Modules\Mail\Integration\{MailDeliveryGateway,MailServices};
use OCA\Mcp\Tests\Integration\Support\{ToolPermissionFixture,LoginFactory};
use OCP\{Server,IDBConnection};
final class MailDeliveryToolActor {
    private const TOOLS=['mail_send','mail_schedule','mail_cancel_delivery','mail_read_delivery','mail_sent_repair','mail_get_operation'];
    private static function call(string $uid,string $tool,array $args=[]):array {
        try{
            $reply=Server::get(GrantPolicy::class)->within($uid,fn()=>Server::get(ToolRegistry::class)->call($tool,$args,$uid));
            if($reply['isError']??false)return ['error'=>true];
            return $reply['structuredContent']??json_decode($reply['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
        }catch(\Throwable $error){return ['error'=>true,'type'=>get_class($error)];}
    }
    private static function confirm(string $uid,string $tool,array $args,array $plan):array {return self::call($uid,$tool,$args+['confirm'=>true,'plan_state'=>$plan['plan_state'],'operation_id'=>$plan['operation_id']]);}
    private static function restoreMail(string $enabled):void {
        $apps=Server::get(\OCP\App\IAppManager::class);
        if($enabled==='yes')$apps->enableApp('mail');
        elseif(is_array($groups=json_decode($enabled,true)))$apps->enableAppForGroups('mail',$groups);
        else $apps->disableApp('mail');
        Server::get(\OCP\IAppConfig::class)->clearCache(false);
    }
    private static function logMarker(string $log):int {
        clearstatcache(true,$log);$offset=filesize($log);$marker='mcp-audit-'.bin2hex(random_bytes(16));
        $r=Server::get(IDBConnection::class)->executeQuery("SELECT '".$marker."'");$r->closeCursor();
        $deadline=microtime(true)+5;
        do{
            $stream=fopen($log,'r');fseek($stream,$offset);$bytes=stream_get_contents($stream,16777216);fclose($stream);
            $pos=strpos($bytes,$marker);if($pos!==false)return $offset+$pos+strlen($marker);
            usleep(100000);
        }while(microtime(true)<$deadline);
        throw new \RuntimeException('Private SQL mirror did not acknowledge audit boundary.');
    }
    /** Interleave only after the real native owner lease is held; the public gateway check already succeeded. */
    private static function cancellationRace(string $uid,string $id,string $mode):array {
        $gateway=Server::get(MailDeliveryGateway::class);$property=new \ReflectionProperty(MailDeliveryGateway::class,'services');$original=$property->getValue($gateway);
        $native=Server::get('OCA\Mail\Service\GuardedDelivery');$store=Server::get('OCA\Mail\Transmission\AttemptStore');$context=$native->accessContext($uid,$id);
        $witness=Server::get(\OCP\Config\IUserConfig::class);$key=\OCA\Mcp\Mcp\Operations\DurableActorIdentity::WITNESS;$oldWitness=$witness->getValueString($uid,'mcp',$key,'');
        $changed=false;$checks=0;$change=function()use($uid,$mode,$witness,$key,&$changed):void {
            $changed=true;
            if($mode==='scope')MailSavedScopeActor::scope($uid,[]);
            elseif($mode==='actor')$witness->deleteUserConfig($uid,'mcp',$key);
            elseif($mode==='commit'){if((MailActor::run($uid,'delivery-cancel-scope-revoke')['changed']??false)!==true)throw new \RuntimeException('Independent scope revocation failed.');}
            else Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($uid):bool{Server::get(ToolGrantsMapper::class)->set('user',$uid,'mail_cancel_delivery','deny');return true;});
        };
        $proxy=new class($native,$store,$change,$mode,$checks){
            public function __construct(private object $native,private object $store,private \Closure $change,private string $mode,public int &$checks){}
            public function __call(string $name,array $args):mixed{return $this->native->$name(...$args);}
            public function cancel(string $uid,string $id,string $revision,?object $fence=null):array {
                return $this->store->coordinator()->runOwner($uid,function()use($uid,$id,$revision,$fence):array {
                    if($this->mode!=='commit')($this->change)();
                    $wrapped=$fence===null?null:new class($fence,$this->change,$this->mode,$this->checks){
                        public function __construct(private object $base,private \Closure $change,private string $mode,public int &$checks){}
                        public function authorize(string $uid,array $origin):void {++$this->checks;if($this->mode==='commit'&&$this->checks===3)($this->change)();$this->base->authorize($uid,$origin);}
                    };
                    return $this->native->cancel($uid,$id,$revision,...($wrapped===null?[]:[$wrapped]));
                });
            }
        };
        $services=new class($original,$proxy)extends MailServices{
            public function __construct(private MailServices $original,private object $proxy){}
            public function isEnabledFor(string $uid):bool{return $this->original->isEnabledFor($uid);}
            public function guardedDelivery(string $uid):object{return $this->proxy;}
        };
        $plan=self::call($uid,'mail_cancel_delivery',['delivery_id'=>$id]);if(!isset($plan['plan_state']))throw new \RuntimeException('Initial cancellation was not authorized.');
        $property->setValue($gateway,$services);
        try{
            $result=self::confirm($uid,'mail_cancel_delivery',['delivery_id'=>$id],$plan);
            $state=$native->status($uid,$id)['state'];$held=false;try{$store->assertFree($uid,$context['draftId']);}catch(\Throwable){$held=true;}
            return ['changed'=>$changed,'checks'=>$checks,'refused'=>($result['error']??false)||(($result['operation']['state']??null)==='uncertain'&&!isset($result['delivery'])),'state'=>$state,'held'=>$held,'cancelled_transitions'=>$state==='cancelled'?1:0];
        }finally{
            $property->setValue($gateway,$original);
            if($mode==='actor')$witness->setValueString($uid,'mcp',$key,$oldWitness,false,\OCP\Config\IUserConfig::FLAG_SENSITIVE|\OCP\Config\IUserConfig::FLAG_INTERNAL);
        }
    }
    public static function run(string $uid,int $draftId,string $scenario):array {
        DeliveryFixtureBoundary::install();
        $config=Server::get(\OCP\IConfig::class);$appConfig=Server::get(\OCP\IAppConfig::class);$appConfig->clearCache(false);
        $oldScope=Server::get(\OCA\Mcp\Db\ResourceScopeMapper::class)->forModule($uid,'mail');$cancelScenario=str_starts_with($scenario,'mcp_cancel_');$scopeScenario=$cancelScenario||in_array($scenario,['mcp_scope_revoked','mcp_future_scope_revoked'],true);
        $mailEnabled=$config->getAppValue('mail','enabled','yes');$disabledGroup=null;
        $auditCursor=$config->getAppValue('mcp','mail_delivery_audit_cursor','__mcp_fixture_absent__');
        $legacy=[];foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY]as$key)$legacy[$key]=$config->getUserValue($uid,'mcp',$key,'__mcp_fixture_absent__');
        $global=$appConfig->hasKey('mcp',GrantPolicy::SERVICE_KEY)?$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY):null;
        $snapshot=ToolPermissionFixture::capture([$uid],array_merge([ToolGrantsMapper::SERVICE],self::TOOLS));
        Server::get(GrantPolicy::class)->setGlobalEnabled(true);Server::get(GrantPolicy::class)->setEligible($uid,true);$mapper=Server::get(ToolGrantsMapper::class);
        Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($mapper,$uid):bool{foreach(array_merge([ToolGrantsMapper::SERVICE],self::TOOLS)as$tool)$mapper->set(ToolGrantsMapper::USER,$uid,$tool,'allow');return true;});
        $context=Server::get(ConnectionContext::class);$context->clear();$context->setSelection(self::TOOLS);$connection=null;$ids=[];$db=Server::get(IDBConnection::class);
        try{
            if($scopeScenario){$sourceAccount=(int)Server::get('OCA\\Mail\\Service\\DraftsService')->getMessage($draftId,$uid)->getAccountId();MailSavedScopeActor::scope($uid,['account:'.$sourceAccount]);}
            $basic=self::call($uid,'mail_send',['draft_id'=>$draftId]);
            $credential=LoginFactory::bearer($uid);$hash=Server::get(TokenHasher::class)->hash(substr($credential->authorization(),7));$row=Server::get(OAuthStore::class)->findByAccess($hash);$connection=(int)$row['id'];
            $store=Server::get(ConnectionToolsStore::class);$selected=$store->get($uid,$connection);$store->save($uid,$connection,self::TOOLS,$selected['revision']);$context->set($connection);$context->setSelection(self::TOOLS);
            // The child uses the real registered worker/authorization closure with the same exact candidate check.
            $command=new \ReflectionProperty(DeliveryRunner::class,'command');$command->setValue(Server::get(DeliveryRunner::class),static fn():array=>['command'=>[PHP_BINARY,__DIR__.'/delivery-tool-child.php'],'cwd'=>dirname(__DIR__)]);
            if($scenario==='mcp_repair'){
                $native=Server::get('OCA\\Mail\\Service\\GuardedDelivery');$preview=$native->preview($uid,$draftId);
                $journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$syntheticArgs=['draft_id'=>$draftId,'account_id'=>$preview['accountId']];$approved=$journal->plan('mail_send',$uid,$syntheticArgs,['plan_state'=>'synthetic-pending-sent']);
                $admission=$journal->execute('mail_send',$uid,$syntheticArgs+['operation_id'=>$approved['operation_id'],'plan_state'=>'synthetic-pending-sent'],function()use($uid,$draftId,$preview,$connection,$journal):array {
                    $journal->recordAuthorization($uid,['v'=>1,'mail'=>[['account'=>$preview['accountId'],'mailbox'=>0]],'global'=>false,'files'=>[],'tools'=>[]]);
                    $key=hash('sha256',$journal->scopeKey($uid)."\0".$journal->activeId($uid));
                    $receipt=Server::get(MailDeliveryGateway::class)->queue($uid,$draftId,$preview['revision'],$key,$connection,'mail_send',0,'UTC');
                    $journal->checkpoint(['delivery_id'=>$receipt['id'],'account_id'=>$preview['accountId'],'source_tool'=>'mail_send']);return ['delivery'=>$receipt];
                });$pending=$admission['delivery'];$id=$pending['id'];$ids[]=$id;
                $dispatch=Server::get(DeliveryRunner::class)->run(['uid'=>$uid,'id'=>$id,'accountId'=>$pending['accountId'],'connectionId'=>$connection,'sourceTool'=>'mail_send']);
                $initial=self::call($uid,'mail_read_delivery',['delivery_id'=>$id]);
                $plan=self::call($uid,'mail_sent_repair',['delivery_id'=>$id]);if(!isset($plan['plan_state']))throw new \RuntimeException('Repair preview failed: '.json_encode(['plan'=>$plan,'dispatch'=>$dispatch,'state'=>$initial['delivery']['state']??null,'sent'=>$initial['delivery']['sentState']??null]));
                $done=self::confirm($uid,'mail_sent_repair',['delivery_id'=>$id],$plan);$again=self::confirm($uid,'mail_sent_repair',['delivery_id'=>$id],$plan);
                return ['before'=>$initial['delivery']['sentState']??null,'after'=>$done['delivery']['sentState']??null,'state'=>$done['delivery']['state']??null,'replayed'=>$again['operation']['replayed']??false,'source_tool'=>$done['operation']['source_tool']??null];
            }
            if($scenario==='mcp_lost_ack'){
                $proxy=new class(Server::get(MailServices::class),new DeliveryFixtureBoundary(Server::get(\OCP\App\IAppManager::class)),Server::get(\OCP\IConfig::class),Server::get(\OCA\Mcp\Modules\Mail\Integration\MailGateway::class),Server::get(\OCA\Mcp\Modules\Mail\Access\MailAccessGuard::class),Server::get(\OCA\Mcp\Modules\Mail\Access\MailDeliveryOrigin::class),$db,Server::get(\OCA\Mcp\Modules\Mail\Access\MailFreshAccess::class))extends MailDeliveryGateway{
                    public function queue(string $uid,int $draft,string $revision,string $operation,int $connection,string $source,int $at,string $zone,?array $sentTarget=null):array {parent::queue($uid,$draft,$revision,$operation,$connection,$source,$at,$zone,$sentTarget);throw new \RuntimeException('Synthetic lost queue response.');}
                };
                (new \ReflectionProperty(MailDeliveryOperations::class,'gateway'))->setValue(Server::get(MailDeliveryOperations::class),$proxy);
            }
            $tool=$cancelScenario||in_array($scenario,['mcp_schedule','mcp_future_revoked','mcp_future_scope_revoked','mcp_future_paused','mcp_mail_revoked'],true)?'mail_schedule':'mail_send';$args=['draft_id'=>$draftId];
            if($cancelScenario||in_array($scenario,['mcp_schedule','mcp_future_revoked','mcp_future_scope_revoked','mcp_future_paused','mcp_mail_revoked'],true))$args+=['send_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'timezone'=>'UTC'];
            $plan=self::call($uid,$tool,$args);if(!isset($plan['plan_state']))throw new \RuntimeException('Actual tool preview failed: '.json_encode($plan));
            $accepted=self::confirm($uid,$tool,$args,$plan);if($scenario==='mcp_lost_ack'){
                $key=hash('sha256',Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class)->scopeKey($uid)."\0".$plan['operation_id']);
                $receipt=Server::get('OCA\\Mail\\Service\\GuardedDelivery')->findByOperation($uid,$key);$id=$receipt['id'];$ids[]=$id;
                $read=self::call($uid,'mail_get_operation',['operation_id'=>$plan['operation_id']]);$replay=self::confirm($uid,$tool,$args,$plan);
                $context->setSelection(array_values(array_diff(self::TOOLS,[$tool])));$revoked=self::call($uid,'mail_get_operation',['operation_id'=>$plan['operation_id']]);
                return ['recovered'=>($read['delivery']['id']??null)===$id,'state'=>$read['delivery']['state']??null,'replayed'=>$replay['operation']['replayed']??false,'source_revoked_read'=>$revoked['error']??false,'private'=>!isset($read['delivery']['artifact'])&&!isset($read['delivery']['bodyPlain'])];
            }
            $id=$accepted['delivery']['id']??null;if(!is_string($id))throw new \RuntimeException('Actual tool admission failed: '.json_encode($accepted));$ids[]=$id;
            $replay=self::confirm($uid,$tool,$args,$plan);$status=self::call($uid,'mail_read_delivery',['delivery_id'=>$id]);
            $out=['basic_refused'=>($basic['error']??false),'admission'=>$accepted['operation']['state']??null,'replayed'=>$replay['operation']['replayed']??false,'delivery_id_preserved'=>($accepted['operation']['resource_refs']['delivery_id']??null)===$id,'status'=>$status['delivery']['state']??null];
            if($cancelScenario)return $out+self::cancellationRace($uid,$id,substr($scenario,11));
            if($scenario==='mcp_schedule'){
                $cancel=self::call($uid,'mail_cancel_delivery',['delivery_id'=>$id]);$done=self::confirm($uid,'mail_cancel_delivery',['delivery_id'=>$id],$cancel);$out['cancelled']=$done['delivery']['state']??null;
                $context->setSelection(array_values(array_diff(self::TOOLS,[$tool])));$out['source_revoked_read']=self::call($uid,'mail_read_delivery',['delivery_id'=>$id])['error']??false;$out['source_revoked_replay']=self::confirm($uid,'mail_cancel_delivery',['delivery_id'=>$id],$cancel)['error']??false;
            }elseif(in_array($scenario,['mcp_due_paused','mcp_future_paused','mcp_mail_revoked'],true)){
                if($scenario==='mcp_mail_revoked'){
                    $disabledGroup=Server::get(\OCP\IGroupManager::class)->createGroup('mcp-it-mail-audit-'.bin2hex(random_bytes(8)));
                    $disabledGroup->addUser(Server::get(\OCP\IUserManager::class)->get(MailActor::BOB));
                    $apps=Server::get(\OCP\App\IAppManager::class);$apps->enableAppForGroups('mail',[$disabledGroup]);$appConfig->clearCache(false);
                    $out['mail_disabled_for_owner']=!Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('mail',Server::get(\OCP\IUserManager::class)->get($uid));
                    $out['mail_enabled_for_other']=Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('mail',Server::get(\OCP\IUserManager::class)->get(MailActor::BOB));
                }
                $config->setSystemValue('app.mail.mcp-transmission.enabled',false);$out['gate_off']=true;
                $log=getenv('MCP_IT_PG_LOG');if(!is_string($log)||!is_readable($log))throw new \RuntimeException('Private metadata SQL proof log unavailable.');
                $offset=self::logMarker($log);
                Server::get(\OCA\Mcp\Modules\Mail\Transmission\DeliverySweep::class)->run();
                $end=self::logMarker($log);if($end-$offset>16777216)throw new \RuntimeException('Audit SQL proof exceeded its bounded window.');
                $stream=fopen($log,'r');fseek($stream,$offset);$sql=stream_get_contents($stream,$end-$offset);fclose($stream);
                preg_match_all('/SELECT\s+(.+?)\s+FROM\s+"?oc_mail_mcp_deliveries"?/i',$sql,$projections);
                $out['audit_metadata_only']=count($projections[1])>0;
                foreach($projections[1] as $projection)if(preg_match('/\*|artifact|sent_proof/i',$projection))$out['audit_metadata_only']=false;
                unset($sql,$projections);
                $native=Server::get('OCA\\Mail\\Service\\GuardedDelivery');$out['native_state']=$native->status($uid,$id)['state'];$out['has_unresolved']=$native->hasUnresolved($uid,$accepted['delivery']['accountId']);
                $out['source_held']=false;try{Server::get('OCA\\Mail\\Transmission\\AttemptStore')->assertFree($uid,$draftId);}catch(\Throwable){$out['source_held']=true;}
                if($scenario==='mcp_mail_revoked'){
                    self::restoreMail($mailEnabled);
                    Server::get(DeliveryRunner::class)->run(['uid'=>$uid,'id'=>$id,'accountId'=>$accepted['delivery']['accountId'],'connectionId'=>$connection,'sourceTool'=>$tool]);
                    $out['restored_state']=$native->status($uid,$id)['state'];
                }
            }elseif(in_array($scenario,['mcp_revoked','mcp_future_revoked','mcp_scope_revoked','mcp_future_scope_revoked'],true)){
                if($scopeScenario){MailSavedScopeActor::scope($uid,[]);$out['scope_status_denied']=self::call($uid,'mail_get_operation',['operation_id'=>$plan['operation_id']])['error']??false;$out['scope_replay_denied']=self::confirm($uid,$tool,$args,$plan)['error']??false;}
                else{$selected=$store->get($uid,$connection);$store->save($uid,$connection,array_values(array_diff(self::TOOLS,[$tool])),$selected['revision']);}
                if(in_array($scenario,['mcp_future_revoked','mcp_future_scope_revoked'],true)){
                    $config->setSystemValue('app.mail.mcp-transmission.enabled',false);
                    Server::get(\OCA\Mcp\Modules\Mail\Transmission\DeliverySweep::class)->run();$out['child']='audited';$out['audit_index']=false;foreach($db->createSchema()->getTable($config->getSystemValueString('dbtableprefix','oc_').'mail_mcp_deliveries')->getIndexes()as$index)if($index->getColumns()===['state','id'])$out['audit_index']=true;$out['gate_off']=!$config->getSystemValueBool('app.mail.mcp-transmission.enabled',false);
                }else{$ack=Server::get(DeliveryRunner::class)->run(['uid'=>$uid,'id'=>$id,'accountId'=>$accepted['delivery']['accountId'],'connectionId'=>$connection,'sourceTool'=>$tool]);$out['child']=$ack['status'];}
                $out['native_state']=Server::get('OCA\\Mail\\Service\\GuardedDelivery')->status($uid,$id)['state'];
                $out['has_unresolved']=Server::get('OCA\\Mail\\Service\\GuardedDelivery')->hasUnresolved($uid,$accepted['delivery']['accountId']);
            }else{
                $ack=Server::get(DeliveryRunner::class)->run(['uid'=>$uid,'id'=>$id,'accountId'=>$accepted['delivery']['accountId'],'connectionId'=>$connection,'sourceTool'=>$tool]);$out['child']=$ack['status'];$after=self::call($uid,'mail_read_delivery',['delivery_id'=>$id]);$out['native_state']=$after['delivery']['state']??null;$out['sent_state']=$after['delivery']['sentState']??null;
            }
            return $out;
        }finally{
            if($scopeScenario)MailSavedScopeActor::scope($uid,$oldScope['items']??null);
            if($disabledGroup!==null){self::restoreMail($mailEnabled);$disabledGroup->delete();}
            foreach($ids as$id){$q=$db->getQueryBuilder();$q->delete('mail_mcp_deliveries')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();}
            if($connection!==null){
                $scope=hash('sha256',$uid."\0".$connection);$q=$db->getQueryBuilder();$q->delete('mcp_operations')->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))->andWhere($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))->executeStatement();
                Server::get(TokenService::class)->revokeGrant($connection,$uid);
            }$context->clear();ToolPermissionFixture::restore($snapshot);
            if($global===null)$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);$appConfig->clearCache(false);
            if($auditCursor==='__mcp_fixture_absent__')$config->deleteAppValue('mcp','mail_delivery_audit_cursor');else $config->setAppValue('mcp','mail_delivery_audit_cursor',$auditCursor);
            foreach($legacy as$key=>$value){if($value==='__mcp_fixture_absent__')$config->deleteUserValue($uid,'mcp',$key);else $config->setUserValue($uid,'mcp',$key,$value);}
            Server::get(\OCP\Config\IUserConfig::class)->clearCache($uid,false);
        }
    }
}
