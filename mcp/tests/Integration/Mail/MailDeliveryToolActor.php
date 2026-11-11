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
    public static function run(string $uid,int $draftId,string $scenario):array {
        DeliveryFixtureBoundary::install();
        $config=Server::get(\OCP\IConfig::class);$appConfig=Server::get(\OCP\IAppConfig::class);$appConfig->clearCache(false);
        $auditCursor=$config->getAppValue('mcp','mail_delivery_audit_cursor','__mcp_fixture_absent__');
        $legacy=[];foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY]as$key)$legacy[$key]=$config->getUserValue($uid,'mcp',$key,'__mcp_fixture_absent__');
        $global=$appConfig->hasKey('mcp',GrantPolicy::SERVICE_KEY)?$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY):null;
        $snapshot=ToolPermissionFixture::capture([$uid],array_merge([ToolGrantsMapper::SERVICE],self::TOOLS));
        Server::get(GrantPolicy::class)->setGlobalEnabled(true);Server::get(GrantPolicy::class)->setEligible($uid,true);$mapper=Server::get(ToolGrantsMapper::class);
        Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($mapper,$uid):bool{foreach(array_merge([ToolGrantsMapper::SERVICE],self::TOOLS)as$tool)$mapper->set(ToolGrantsMapper::USER,$uid,$tool,'allow');return true;});
        $context=Server::get(ConnectionContext::class);$context->clear();$context->setSelection(self::TOOLS);$connection=null;$ids=[];$db=Server::get(IDBConnection::class);
        try{
            $basic=self::call($uid,'mail_send',['draft_id'=>$draftId]);
            $credential=LoginFactory::bearer($uid);$hash=Server::get(TokenHasher::class)->hash(substr($credential->authorization(),7));$row=Server::get(OAuthStore::class)->findByAccess($hash);$connection=(int)$row['id'];
            $store=Server::get(ConnectionToolsStore::class);$selected=$store->get($uid,$connection);$store->save($uid,$connection,self::TOOLS,$selected['revision']);$context->set($connection);$context->setSelection(self::TOOLS);
            // The child uses the real registered worker/authorization closure with the same exact candidate check.
            $command=new \ReflectionProperty(DeliveryRunner::class,'command');$command->setValue(Server::get(DeliveryRunner::class),static fn():array=>['command'=>[PHP_BINARY,__DIR__.'/delivery-tool-child.php'],'cwd'=>dirname(__DIR__)]);
            if($scenario==='mcp_repair'){
                $native=Server::get('OCA\\Mail\\Service\\GuardedDelivery');$preview=$native->preview($uid,$draftId);
                $pending=$native->queue($uid,$draftId,$preview['revision'],bin2hex(random_bytes(16)),$connection,'mail_send',0,'UTC');$id=$pending['id'];$ids[]=$id;
                Server::get(DeliveryRunner::class)->run(['uid'=>$uid,'id'=>$id,'accountId'=>$pending['accountId'],'connectionId'=>$connection,'sourceTool'=>'mail_send']);
                $initial=self::call($uid,'mail_read_delivery',['delivery_id'=>$id]);
                $plan=self::call($uid,'mail_sent_repair',['delivery_id'=>$id]);if(!isset($plan['plan_state']))throw new \RuntimeException('Repair preview failed: '.json_encode($plan));
                $done=self::confirm($uid,'mail_sent_repair',['delivery_id'=>$id],$plan);$again=self::confirm($uid,'mail_sent_repair',['delivery_id'=>$id],$plan);
                return ['before'=>$initial['delivery']['sentState']??null,'after'=>$done['delivery']['sentState']??null,'state'=>$done['delivery']['state']??null,'replayed'=>$again['operation']['replayed']??false,'source_tool'=>$done['operation']['source_tool']??null];
            }
            if($scenario==='mcp_lost_ack'){
                $proxy=new class(Server::get(MailServices::class),new DeliveryFixtureBoundary(Server::get(\OCP\App\IAppManager::class)),Server::get(\OCP\IConfig::class),$db)extends MailDeliveryGateway{
                    public function queue(string $uid,int $draft,string $revision,string $operation,int $connection,string $source,int $at,string $zone,?array $sentTarget=null):array {parent::queue($uid,$draft,$revision,$operation,$connection,$source,$at,$zone,$sentTarget);throw new \RuntimeException('Synthetic lost queue response.');}
                };
                (new \ReflectionProperty(MailDeliveryOperations::class,'gateway'))->setValue(Server::get(MailDeliveryOperations::class),$proxy);
            }
            $tool=in_array($scenario,['mcp_schedule','mcp_future_revoked'],true)?'mail_schedule':'mail_send';$args=['draft_id'=>$draftId];
            if(in_array($scenario,['mcp_schedule','mcp_future_revoked'],true))$args+=['send_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'timezone'=>'UTC'];
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
            if($scenario==='mcp_schedule'){
                $cancel=self::call($uid,'mail_cancel_delivery',['delivery_id'=>$id]);$done=self::confirm($uid,'mail_cancel_delivery',['delivery_id'=>$id],$cancel);$out['cancelled']=$done['delivery']['state']??null;
                $context->setSelection(array_values(array_diff(self::TOOLS,[$tool])));$out['source_revoked_read']=self::call($uid,'mail_read_delivery',['delivery_id'=>$id])['error']??false;$out['source_revoked_replay']=self::confirm($uid,'mail_cancel_delivery',['delivery_id'=>$id],$cancel)['error']??false;
            }elseif(in_array($scenario,['mcp_revoked','mcp_future_revoked'],true)){
                $selected=$store->get($uid,$connection);$store->save($uid,$connection,array_values(array_diff(self::TOOLS,[$tool])),$selected['revision']);
                if($scenario==='mcp_future_revoked'){
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
