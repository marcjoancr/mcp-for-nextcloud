<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IDBConnection,IConfig};
use OCA\Mcp\Modules\Mail\Snooze\{SnoozeSweep,SnoozeRunner};
use OCA\Mcp\Modules\Mail\Integration\MailSnoozeGateway;
/** Actual PostgreSQL query+sweep; 25 native waiting_cache rows precede a real originating OAuth intent. */
final class MailSnoozeFairActor {
    public static function run(string $uid,string $id,array $fixture,string $scenario):array {
        $db=Server::get(IDBConnection::class);$store=Server::get('OCA\\Mail\\Snooze\\SnoozeStore');$native=Server::get('OCA\\Mail\\Service\\GuardedSnooze');$row=$store->find($uid,$id);$config=Server::get(IConfig::class);$saved=[];foreach(['mail_snooze_audit_cursor','mail_snooze_dispatch_cursor']as$key){$saved[$key]=$config->getAppValue('mcp',$key,'');$config->setAppValue('mcp',$key,'');}$ids=[];
        try{
            if($scenario==='mcp_fair_ready'){MailNativeMoveActor::inspect($uid,$fixture,$fixture['boxes']['destination'],true);$q=$db->getQueryBuilder();$q->update('mail_mcp_snoozes')->set('wake_at',$q->createNamedParameter(time()-1))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();}
            for($i=1;$i<=25;$i++){$copy=$row;$copy['id']=str_pad(dechex($i),64,'0',STR_PAD_LEFT);$copy['operation_key']=bin2hex(random_bytes(32));$copy['active_source']=$copy['operation_key'];$copy['state']='snoozed';$copy['placement_phase']='completed';$copy['wake_phase']='prepared';$copy['wake_at']=time()-3600;$copy['current_mailbox_id']=$copy['snooze_mailbox_id'];$copy['current_generation']=$copy['snooze_generation'];$copy['current_uid']=(string)(1000000+$i);$q=$db->getQueryBuilder();$q->insert('mail_mcp_snoozes');foreach($copy as$key=>$value)$q->setValue($key,$q->createNamedParameter($value));$q->executeStatement();$ids[]=$copy['id'];}
            if($scenario==='mcp_fair_revoked')MailSavedScopeActor::scope($uid,['mailbox:'.$fixture['boxes']['destination']]);
            $runner=new class(Server::get(SnoozeRunner::class),$native,$ids)extends SnoozeRunner{
                public array $seen=[];public int $waiting=0;
                public function __construct(private SnoozeRunner $original,private object $native,private array $synthetic){}
                public function run(array $r,string $action='dispatch',?string $revision=null,?string $operation=null,int $budgetMs=20000):array {
                    $this->seen[]=[$r['id'],$action];if(!in_array($r['id'],$this->synthetic,true))return $this->original->run($r,$action,$revision,$operation,$budgetMs);
                    // Only synthetic queue ballast uses this native fence; the target retains the actual signed OAuth worker.
                    if($action==='dispatch'){$s=$this->native->wake($r['uid'],$r['id'],null,new class{public function authorize(string $uid,array $origin):void{}});if(($s['readiness']??'')==='waiting_cache')$this->waiting++;}return ['status'=>'completed'];
                }
            };
            $sweep=new SnoozeSweep(Server::get(MailSnoozeGateway::class),$runner,$config);$sweep->run();$first=$store->find($uid,$id)['state'];$waiting=$runner->waiting;$sweep->run();$final=$store->find($uid,$id)['state'];
            return ['first_state'=>$first,'final_state'=>$final,'waiting_first'=>$waiting,'target_dispatched'=>in_array([$id,'dispatch'],$runner->seen,true),'fake_holds_retained'=>array_reduce($ids,fn(bool $ok,string $fake):bool=>$ok&&$store->find($uid,$fake)['active_source']!==null,true)];
        }finally{foreach($ids as$fake){$q=$db->getQueryBuilder();$q->delete('mail_mcp_snoozes')->where($q->expr()->eq('id',$q->createNamedParameter($fake)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();}foreach($saved as$key=>$value)$config->setAppValue('mcp',$key,$value);}
    }
}
