<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IDBConnection,IConfig};
final class MailSieveActor {
    public static function filter():array{return [['name'=>'Synthetic reviewed','enable'=>true,'priority'=>1,'operator'=>'allof','tests'=>[['field'=>'subject','operator'=>'contains','values'=>['TEST']]],'actions'=>[['type'=>'keep'],['type'=>'stop']]]];}
    private static function fence():object{return new class{public function authorize(string $uid,array $r):void{}};}
    public static function run(string $uid,array $in):array {
        if(!class_exists('OCA\\Mail\\Service\\GuardedSieveSettings'))return ['implementation_available'=>false];$scenario=$in['scenario'];$config=Server::get(IConfig::class);if($config->getSystemValueBool('app.mail.mcp-transmission.enabled',false))throw new \RuntimeException('Synthetic fixture transmission must start disabled.');
        $account=Server::get('OCA\\Mail\\Service\\AccountService')->findByUserId($uid)[0];$id=(int)$account->getId();$old=$account->getMailAccount();$fields=['SieveEnabled'=>$old->isSieveEnabled(),'SieveHost'=>$old->getSieveHost(),'SievePort'=>$old->getSievePort(),'SieveSslMode'=>$old->getSieveSslMode(),'SieveUser'=>$old->getSieveUser(),'SievePassword'=>$old->getSievePassword(),'OutOfOfficeFollowsSystem'=>$old->getOutOfOfficeFollowsSystem()];
        $peer=proc_open([PHP_BINARY,__DIR__.'/sieve-server.php',$scenario],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($peer))throw new \RuntimeException('Peer unavailable');$port=json_decode(fgets($pipes[1]),true)['port'];$attempt=null;$attempts=[];$out=['implementation_available'=>true];
        try{
            $a=clone $old;$a->setSieveEnabled(true);$a->setSieveHost('127.0.0.1');$a->setSievePort($port);$a->setSieveSslMode('ssl');$a->setSieveUser('synthetic');$a->setSievePassword(Server::get(\OCP\Security\ICrypto::class)->encrypt('AUTH_SYNTHETIC_PRIVATE'));$a->setOutOfOfficeFollowsSystem($scenario==='disable_follow_revoke');Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($a);
            $native=Server::get('OCA\\Mail\\Service\\GuardedSieveSettings');$fence=self::fence();$c=['filters'=>self::filter()];
            if(in_array($scenario,['opaque','collision'],true)){
                $out['opaque']=$native->read($uid,$id,$fence)['opaque_sections'];$out['refused']=self::refused(function()use($native,$uid,$id,$c,$fence){$p=$native->preview($uid,$id,'mail_write_filters',$c,$fence);$native->apply($uid,$id,'mail_write_filters',$c,$p['revision'],str_repeat('a',64),1,$fence);});
            }elseif($scenario==='absence_cycle'){
                $config->setSystemValue('app.mail.mcp-transmission.enabled',true);
                try{$absence=['absence'=>['version'=>1,'enabled'=>true,'start'=>'2026-11-11T00:00:00Z','end'=>'2026-11-12T00:00:00Z','subject'=>'Away ${subject}','message'=>'PRIVATE_ABSENCE_CANARY'],'follow_system'=>true];$p=$native->preview($uid,$id,'mail_enable_absence',$absence,$fence);$r=$native->apply($uid,$id,'mail_enable_absence',$absence,$p['revision'],bin2hex(random_bytes(32)),1,$fence);$attempts[]=$r['id'];$out['enable_phase']=$r['phase'];$out['following']=$native->read($uid,$id,$fence)['following_system'];$out['enabled_status']=$native->status($uid,$r['id'],$fence)['observation'];}
                finally{$config->setSystemValue('app.mail.mcp-transmission.enabled',false);}
                $out['safe_filters_refused']=self::refused(fn()=>$native->preview($uid,$id,'mail_write_filters',$c,$fence));$p=$native->preview($uid,$id,'mail_disable_absence',[],$fence);$r=$native->apply($uid,$id,'mail_disable_absence',[],$p['revision'],bin2hex(random_bytes(32)),1,$fence);$attempts[]=$r['id'];$out['disable_phase']=$r['phase'];$read=$native->read($uid,$id,$fence);$out['disabled']=!$read['absence']['enabled']&&!$read['following_system'];$out['disabled_status']=$native->status($uid,$r['id'],$fence)['observation'];
            }elseif($scenario==='native_logger'){
                $path='/tmp/mcp-sieve-private-'.bin2hex(random_bytes(8)).'.log';$client=null;
                try{$client=Server::get('OCA\\Mail\\Sieve\\SieveClientFactory')->createClient('127.0.0.1',$port,'synthetic','AUTH_SYNTHETIC_PRIVATE','ssl',$path);$client->getScript('original');$out['protocol_log_absent']=!is_file($path)||filesize($path)===0;}finally{$client?->disconnect();if(is_file($path))unlink($path);}
            }elseif($scenario==='disable_follow_revoke'){
                $p=$native->preview($uid,$id,'mail_disable_absence',[],$fence);$probe=new class($id){public bool $reached=false;public function __construct(private int $id){}public function authorize(string $uid,array $origin):void{$db=Server::get(IDBConnection::class);$a=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$this->id)->getMailAccount();if(!$a->getOutOfOfficeFollowsSystem()){$this->reached=$db->inTransaction();throw new \RuntimeException('Synthetic current grant withdrawn before local commit.');}}};
                $r=$native->apply($uid,$id,'mail_disable_absence',[],$p['revision'],bin2hex(random_bytes(32)),1,$probe);$attempt=$r['id'];$out['precommit_reached']=$probe->reached;$out['follow_preserved']=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$id)->getMailAccount()->getOutOfOfficeFollowsSystem();$out['phase']=$r['phase'];
            }elseif($scenario==='mcp')$out+=MailSieveToolActor::run($uid,$id);
            else{
                $p=$native->preview($uid,$id,'mail_write_filters',$c,$fence);$key=bin2hex(random_bytes(32));$r=$native->apply($uid,$id,'mail_write_filters',$c,$p['revision'],$key,1,$fence);$attempt=$r['id'];$out['phase']=$r['phase'];$out['state']=$r['state'];$out['replay']=$native->apply($uid,$id,'mail_write_filters',$c,$p['revision'],$key,1,$fence)===$r;$out['status']=$native->status($uid,$r['id'],$fence);
                $read=$native->read($uid,$id,$fence);$out['recognized']=!$read['opaque_sections'];$out['filters_match']=$read['filters']===($scenario==='lost_put'||$scenario==='external_before_active'?[]:self::filter());$out['no_raw']=!str_contains(json_encode([$r,$out['status'],$read]),'SYNTHETIC_PRIVATE');
                $out['transmit_refused']=self::refused(fn()=>$native->preview($uid,$id,'mail_transmit_filters',$c,$fence));
                $out['vacation_refused']=self::refused(fn()=>$native->preview($uid,$id,'mail_enable_absence',['absence'=>['version'=>1,'enabled'=>true,'start'=>'2026-11-11T00:00:00Z','end'=>'2026-11-12T00:00:00Z','subject'=>'Away','message'=>'TEST'],'follow_system'=>true],$fence));
                if($scenario==='normal'){
                    $out['stale_refused']=self::refused(fn()=>$native->apply($uid,$id,'mail_write_filters',$c,$p['revision'],bin2hex(random_bytes(32)),1,$fence));
                    $before=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$id)->getMailAccount()->getMcpAuthRevision();$a=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$id)->getMailAccount();$a->setSievePassword(Server::get(\OCP\Security\ICrypto::class)->encrypt('AUTH_SYNTHETIC_CHANGED'));Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($a);$out['secret_revision_bumped']=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$id)->getMailAccount()->getMcpAuthRevision()>$before;
                }
            }
        }finally{
            if($attempt!==null)$attempts[]=$attempt;foreach($attempts as$attempt){$q=Server::get(IDBConnection::class)->getQueryBuilder();$q->delete('mail_mcp_sieve')->where($q->expr()->eq('id',$q->createNamedParameter($attempt)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();}
            $a=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$id)->getMailAccount();foreach($fields as$k=>$v)$a->{'set'.$k}($v);Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($a);
            fclose($pipes[0]);$raw=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($peer);if($exit!==0||$errors!=='')throw new \RuntimeException('Synthetic Sieve peer failed.');$out['peer']=json_decode(trim($raw),true,32,JSON_THROW_ON_ERROR);$out['operator_off']=!$config->getSystemValueBool('app.mail.mcp-transmission.enabled',false);
        }return $out;
    }
    private static function refused(\Closure $f):bool {try{$f();return false;}catch(\Throwable){return true;}}
}
