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
use OCA\Mcp\Modules\Mail\Snooze\{SnoozeRunner,SnoozeSweep};
use OCA\Mcp\Modules\Mail\Integration\{MailSnoozeGateway,MailServices};
use OCA\Mcp\Tests\Integration\Support\{ToolPermissionFixture,LoginFactory};
use OCP\{Server,IDBConnection};
final class MailSnoozeToolActor {
    private const TOOLS=['mail_snooze','mail_wake','mail_cancel_snooze','mail_read_snooze','mail_get_operation'];
    private static function call(string $uid,string $tool,array $args):array {
        try{$r=Server::get(GrantPolicy::class)->within($uid,fn()=>Server::get(ToolRegistry::class)->call($tool,$args,$uid));if($r['isError']??false)return ['error'=>true];return $r['structuredContent']??json_decode($r['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);}catch(\Throwable $e){return ['error'=>true,'type'=>get_class($e)];}
    }
    private static function confirm(string $uid,string $tool,array $args,array $p):array {return self::call($uid,$tool,$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);}
    private static function logMarker(string $log):int {
        clearstatcache(true,$log);$offset=filesize($log);$marker='mcp-snooze-audit-'.bin2hex(random_bytes(16));$r=Server::get(IDBConnection::class)->executeQuery("SELECT '".$marker."'");$r->closeCursor();$deadline=microtime(true)+5;
        do{$stream=fopen($log,'r');fseek($stream,$offset);$bytes=stream_get_contents($stream,16777216);fclose($stream);$pos=strpos($bytes,$marker);if($pos!==false)return $offset+$pos+strlen($marker);usleep(100000);}while(microtime(true)<$deadline);
        throw new \RuntimeException('Private SQL mirror did not acknowledge snooze audit boundary.');
    }
    public static function run(string $uid,array $fixture,string $scenario):array {
        SnoozeFixtureBoundary::install();$config=Server::get(\OCP\IConfig::class);$app=Server::get(\OCP\IAppConfig::class);$db=Server::get(IDBConnection::class);$context=Server::get(ConnectionContext::class);$connection=null;$id=null;
        $snapshot=ToolPermissionFixture::capture([$uid],array_merge([ToolGrantsMapper::SERVICE],self::TOOLS));$scope=Server::get(\OCA\Mcp\Db\ResourceScopeMapper::class)->forModule($uid,'mail');$global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__absent');$legacy=[];foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY]as$key)$legacy[$key]=$config->getUserValue($uid,'mcp',$key,'__absent');$cursor=$config->getAppValue('mcp','mail_snooze_audit_cursor','');
        Server::get(GrantPolicy::class)->setGlobalEnabled(true);Server::get(GrantPolicy::class)->setEligible($uid,true);Server::get(GrantPolicy::class)->setConnected($uid,true);
        Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($uid):bool{foreach(array_merge([ToolGrantsMapper::SERVICE],self::TOOLS)as$tool)Server::get(ToolGrantsMapper::class)->set('user',$uid,$tool,'allow');return true;});
        MailSavedScopeActor::scope($uid,['mailbox:'.$fixture['boxes']['source'],'mailbox:'.$fixture['boxes']['destination']]);$args=['message_id'=>$fixture['messages'][0],'wake_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'timezone'=>'UTC'];$gateway=Server::get(MailSnoozeGateway::class);$servicesProp=new \ReflectionProperty(MailSnoozeGateway::class,'services');$services=$servicesProp->getValue($gateway);
        try{
            $context->clear();$context->setSelection(self::TOOLS);$basic=self::call($uid,'mail_snooze',$args);
            $credential=LoginFactory::bearer($uid);$row=Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash(substr($credential->authorization(),7)));$connection=(int)$row['id'];$selection=Server::get(ConnectionToolsStore::class);$old=$selection->get($uid,$connection);$selection->save($uid,$connection,self::TOOLS,$old['revision']);$context->set($connection);$context->setSelection(self::TOOLS);
            (new \ReflectionProperty(SnoozeRunner::class,'command'))->setValue(Server::get(SnoozeRunner::class),static fn():array=>['command'=>[PHP_BINARY,__DIR__.'/snooze-tool-child.php'],'cwd'=>dirname(__DIR__)]);
            $fpmRefused=!(new \OCA\Mcp\Tests\Integration\Support\McpClient($credential))->tool('mail_snooze',$args)->ok();
            $p=self::call($uid,'mail_snooze',$args);if(!isset($p['plan_state']))throw new \RuntimeException('Snooze preview failed: '.json_encode($p));
            if(in_array($scenario,['mcp_lost_ack','mcp_fair_prepared','mcp_fair_revoked'],true)){
                $proxy=new class(Server::get('OCA\\Mail\\Service\\GuardedSnooze')){public function __construct(private object $native){}public function __call(string $name,array $args):mixed{return $this->native->$name(...$args);}public function schedule(...$args):array{$this->native->schedule(...$args);throw new \RuntimeException('Synthetic lost native admission acknowledgement');}};
                $servicesProp->setValue($gateway,new class($services,$proxy)extends MailServices{public function __construct(private MailServices $base,private object $proxy){}public function guardedSnooze(string $uid):object{return $this->proxy;}public function snoozeAuthorization():object{return $this->base->snoozeAuthorization();}});
            }
            $accepted=self::confirm($uid,'mail_snooze',$args,$p);$servicesProp->setValue($gateway,$services);$read=self::call($uid,'mail_get_operation',['operation_id'=>$p['operation_id']]);$id=$read['snooze']['id']??null;if(!is_string($id))throw new \RuntimeException('Snooze admission/recovery failed: '.json_encode([$accepted,$read]));
            $out=['fpm_unqualified_refused'=>$fpmRefused,'basic_refused'=>$basic['error']??false,'admission'=>$accepted['operation']['state']??null,'state'=>$read['snooze']['state'],'id_preserved'=>($read['operation']['resource_refs']['snooze_id']??null)===$id];
            $replay=self::confirm($uid,'mail_snooze',$args,$p);$out['replayed']=$replay['operation']['replayed']??false;$out['status']=self::call($uid,'mail_read_snooze',['snooze_id'=>$id])['snooze']['state']??null;
            if(str_starts_with($scenario,'mcp_fair_'))return $out+MailSnoozeFairActor::run($uid,$id,$fixture,$scenario);
            if($scenario==='mcp_lost_ack'){Server::get(SnoozeSweep::class)->run();$out['recovered_state']=self::call($uid,'mail_read_snooze',['snooze_id'=>$id])['snooze']['state']??null;return $out;}
            if($scenario==='mcp_scope'){
                MailSavedScopeActor::scope($uid,['mailbox:'.$fixture['boxes']['destination']]);$out['scope_status_denied']=self::call($uid,'mail_read_snooze',['snooze_id'=>$id])['error']??false;$out['scope_replay_denied']=self::confirm($uid,'mail_snooze',$args,$p)['error']??false;$log=getenv('MCP_IT_PG_LOG');if(!is_string($log)||!is_readable($log))throw new \RuntimeException('SQL proof log unavailable.');$offset=self::logMarker($log);Server::get(SnoozeSweep::class)->run();$end=self::logMarker($log);if($end-$offset>16777216)throw new \RuntimeException('SQL proof window exceeded.');$stream=fopen($log,'r');fseek($stream,$offset);$sql=stream_get_contents($stream,$end-$offset);fclose($stream);
                preg_match_all('/SELECT\s+(.+?)\s+FROM\s+"?oc_mail_mcp_snoozes"?/i',$sql,$projections);$out['audit_metadata_only']=count($projections[1])>0;foreach($projections[1]as$projection)if(preg_match('/\*|_request|_proof/i',$projection))$out['audit_metadata_only']=false;unset($sql,$projections);
                $out['blocked']=Server::get('OCA\\Mail\\Snooze\\SnoozeStore')->find($uid,$id)['state'];return $out;
            }
            if($scenario==='mcp_wake'){
                MailNativeMoveActor::inspect($uid,$fixture,$fixture['boxes']['destination'],true);$p2=self::call($uid,'mail_wake',['snooze_id'=>$id]);if(!isset($p2['plan_state']))throw new \RuntimeException('Wake preview failed: '.json_encode($p2));$done=self::confirm($uid,'mail_wake',['snooze_id'=>$id],$p2);$out['woken']=$done['snooze']['state']??null;
            }else{
                $p2=self::call($uid,'mail_cancel_snooze',['snooze_id'=>$id]);if(!isset($p2['plan_state']))throw new \RuntimeException('Cancel preview failed');
                if($scenario==='mcp_cancel_revoke'){
                    $native=Server::get('OCA\\Mail\\Service\\GuardedSnooze');$proxy=new class($native){public function __construct(private object $native){}public function __call(string $name,array $args):mixed{return $this->native->$name(...$args);}public function cancel(string $uid,string $id,string $revision,object $fence):array{$wrapped=new class($fence){private int $checks=0;public function __construct(private object $base){}public function authorize(string $uid,array $origin):void{if(++$this->checks===3)MailActor::run($uid,'delivery-cancel-scope-revoke');$this->base->authorize($uid,$origin);}};return $this->native->cancel($uid,$id,$revision,$wrapped);}};
                    $servicesProp->setValue($gateway,new class($services,$proxy)extends MailServices{public function __construct(private MailServices $base,private object $proxy){}public function guardedSnooze(string $uid):object{return $this->proxy;}});
                }
                $done=self::confirm($uid,'mail_cancel_snooze',['snooze_id'=>$id],$p2);$out['cancelled']=$done['snooze']['state']??null;$out['native_state']=Server::get('OCA\\Mail\\Snooze\\SnoozeStore')->find($uid,$id)['state'];$out['refused']=($done['error']??false)||($done['operation']['state']??null)==='uncertain';
            }
            return $out;
        }finally{
            $servicesProp->setValue($gateway,$services);MailSavedScopeActor::scope($uid,$scope['items']??null);
            if($id!==null){$q=$db->getQueryBuilder();$q->delete('mail_mcp_snoozes')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();}
            if($connection!==null){$scopeKey=hash('sha256',$uid."\0".$connection);foreach(['mcp_operation_origins','mcp_operations']as$table){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('scope_key',$q->createNamedParameter($scopeKey)))->executeStatement();}Server::get(TokenService::class)->revokeGrant($connection,$uid);}
            $context->clear();ToolPermissionFixture::restore($snapshot);if($global==='__absent')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);$app->clearCache(false);foreach($legacy as$key=>$value){if($value==='__absent')$config->deleteUserValue($uid,'mcp',$key);else $config->setUserValue($uid,'mcp',$key,$value);}Server::get(\OCP\Config\IUserConfig::class)->clearCache($uid,false);$config->setAppValue('mcp','mail_snooze_audit_cursor',$cursor);
        }
    }
}
