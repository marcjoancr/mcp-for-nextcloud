<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Sieve\{SieveStore,SieveProgram,SieveClientFactory,BoundedManageSieve,SieveFailure};
use OCA\Mail\Db\{MailboxMapper,MailAccountMapper};
use OCP\{IDBConnection,IConfig};
/** Native owner serialization + optimistic observations, never external ManageSieve CAS. */
final class GuardedSieveSettings {
    public function __construct(private SieveStore $store,private SieveProgram $program,private SieveClientFactory $clients,private AccountService $accounts,private MailboxMapper $boxes,private IDBConnection $db,private IConfig $config,private MailAccountMapper $accountMapper){}
    private function owner(string $uid,int $id,object $fence):object {
        $a=$this->accounts->find($uid,$id);if($a->getUserId()!==$uid||(int)$a->getId()!==$id)throw new SieveFailure();(new \OCA\Mail\Lifecycle\MailErasureStore($this->db))->assertAccount($uid,$id);$fence->authorize($uid,['accountId'=>$id]);return $a;
    }
    private function recipients(string $uid,#[\SensitiveParameter] object $account):array {
        $a=$account->getMailAccount();$q=$this->db->getQueryBuilder();$q->select('alias')->from('mail_aliases')->where($q->expr()->eq('account_id',$q->createNamedParameter((int)$a->getId(),\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->setMaxResults(100);$r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}if(count($rows)>=100)throw new SieveFailure();$out=[$this->program->address($a->getEmail())];foreach($rows as$row)$out[]=$this->program->address($row['alias']);return $out;
    }
    private function profile(#[\SensitiveParameter] object $account,#[\SensitiveParameter] array $recipients):string {$a=$account->getMailAccount();return hash('sha256',json_encode([(int)$a->getId(),$a->getMcpAuthRevision(),$a->getOutOfOfficeFollowsSystem(),$recipients],JSON_THROW_ON_ERROR));}
    private function snapshot(BoundedManageSieve $c):array {$list=$c->scripts();$name=$list['active'];$script=$name===null?'':$c->getScript($name);return ['name'=>$name??'','hash'=>hash('sha256',$script),'script'=>$script,'names'=>$list['names']];}
    private function settings(string $uid,int $id,object $fence):array {$a=$this->owner($uid,$id,$fence);$recipients=$this->recipients($uid,$a);return [$a,$recipients,$this->profile($a,$recipients)];}
    public function read(string $uid,int $id,object $fence):array {
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id,$fence){[$a,$recipients,$profile]=$this->settings($uid,$id,$fence);$c=$this->clients->getGuardedClient($a);try{$s=$this->snapshot($c);$p=$this->program->parse($s['script'],$recipients);$fence->authorize($uid,['accountId'=>$id]);return ['account_id'=>$id,'filters'=>$p['filters'],'absence'=>$p['absence'],'opaque_sections'=>$p['opaque'],'following_system'=>(bool)$a->getMailAccount()->getOutOfOfficeFollowsSystem(),'capabilities'=>$c->getExtensions(),'revision'=>hash('sha256',json_encode([$s['name'],$s['hash'],$profile],JSON_THROW_ON_ERROR)),'external_cas'=>false];}finally{$c->close();}});
    }
    private function proposal(string $uid,int $id,string $source,#[\SensitiveParameter] array $config,object $fence,BoundedManageSieve $client,#[\SensitiveParameter] object $account,#[\SensitiveParameter] array $recipients,string $profile):array {
        $s=$this->snapshot($client);$old=$this->program->parse($s['script'],$recipients);$transmit=in_array($source,['mail_transmit_filters','mail_enable_absence'],true);
        if($transmit&&!$this->config->getSystemValueBool('app.mail.mcp-transmission.enabled',false))throw new SieveFailure();
        $filters=null;$absence=null;$following=(bool)$account->getMailAccount()->getOutOfOfficeFollowsSystem();
        if(in_array($source,['mail_write_filters','mail_transmit_filters'],true)){$filters=$config['filters']??throw new SieveFailure();if(array_keys($config)!==['filters'])throw new SieveFailure();}
        elseif(in_array($source,['mail_write_absence','mail_enable_absence'],true)){
            if(array_diff(array_keys($config),['absence','follow_system'])!==[]||!isset($config['absence'])||!is_bool($config['follow_system']??null))throw new SieveFailure();$absence=$this->program->validateAbsence($config['absence']);$following=$config['follow_system'];if(!$transmit&&($absence['enabled']||$following))throw new SieveFailure();
        }elseif($source==='mail_disable_absence'){
            if($config!==[]||$old['opaque'])throw new SieveFailure();$absence=$old['absence']??['version'=>1,'enabled'=>false,'subject'=>'','message'=>''];$absence['enabled']=false;unset($absence['start'],$absence['end']);$following=false;
        }else throw new SieveFailure();
        if($following&&!$transmit)throw new SieveFailure();$candidate=$this->program->build($old,$filters,$absence,$recipients,$client->getExtensions(),$transmit);$this->accountMapper->assertSieveFollowing($uid,$id,$following,$account->getMailAccount()->getMcpAuthRevision());
        foreach($candidate['filters']as$f)foreach($f['actions']as$action)if($action['type']==='fileinto'){$b=$this->boxes->find($account,$action['mailbox']);if(!$b->getSelectable()||$b->getAccountId()!==$id)throw new SieveFailure();}
        $revision=hash('sha256',json_encode([$id,$source,$s['name'],$s['hash'],$profile,hash('sha256',$candidate['script']),$following,$candidate['extensions']],JSON_THROW_ON_ERROR));
        $public=['account_id'=>$id,'revision'=>$revision,'following_system'=>$following,'emits'=>$candidate['emits']||$following,'external_cas'=>false,'opaque_sections'=>false];
        if($filters!==null)$public['filters']=$candidate['filters'];else $public['absence']=$candidate['absence'];
        return ['snapshot'=>$s,'candidate'=>$candidate,'profile'=>$profile,'following'=>$following,'public'=>$public];
    }
    public function preview(string $uid,int $id,string $source,#[\SensitiveParameter] array $config,object $fence):array {
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id,$source,$config,$fence){[$a,$recipients,$profile]=$this->settings($uid,$id,$fence);$c=$this->clients->getGuardedClient($a);try{$p=$this->proposal($uid,$id,$source,$config,$fence,$c,$a,$recipients,$profile);$fence->authorize($uid,['accountId'=>$id]);return $p['public'];}finally{$c->close();}});
    }
    public function apply(string $uid,int $id,string $source,#[\SensitiveParameter] array $config,string $revision,string $key,int $connection,object $fence):array {
        if($connection<1||preg_match('/^[a-f0-9]{64}$/D',$key)!==1||preg_match('/^[a-f0-9]{64}$/D',$revision)!==1)throw new SieveFailure();
        $approval=hash('sha256',json_encode([$uid,$id,$source,$config,$revision,$key,$connection],JSON_THROW_ON_ERROR));
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id,$source,$config,$revision,$key,$connection,$fence,$approval){
            $prior=$this->store->replay($uid,$key);if($prior!==null){$fence->authorize($uid,SieveStore::origin($prior));if($prior['approval']!==$approval)throw new SieveFailure();return SieveStore::receipt($prior);}
            [$a,$recipients,$profile]=$this->settings($uid,$id,$fence);$c=$this->clients->getGuardedClient($a);$r=null;
            try{
                $p=$this->proposal($uid,$id,$source,$config,$fence,$c,$a,$recipients,$profile);if(!hash_equals($revision,$p['public']['revision']))throw new SieveFailure();$old=$p['snapshot'];$candidate='mcp-'.$key;if(in_array($candidate,$old['names'],true))throw new SieveFailure();
                $check=$this->snapshot($c);if($check['name']!==$old['name']||$check['hash']!==$old['hash']||in_array($candidate,$check['names'],true))throw new SieveFailure();
                $r=['id'=>bin2hex(random_bytes(32)),'user_id'=>$uid,'account_id'=>$id,'connection_id'=>$connection,'operation_key'=>hash('sha256',$key),'source_tool'=>$source,'approval'=>$approval,'revision'=>1,'state'=>'prepared','candidate'=>$candidate,'candidate_hash'=>hash('sha256',$p['candidate']['script']),'original_name'=>$old['name'],'original_hash'=>$old['hash'],'profile'=>$profile,'observed_profile'=>'','created_at'=>time(),'follow_system'=>(int)$p['following'],'program'=>$p['candidate']['script']];
                $authorize=function()use($uid,$id,$fence,$r,$profile,$p):void{$fence->authorize($uid,SieveStore::origin($r));[$current,$recipients,$now]=$this->settings($uid,$id,$fence);if($profile!==$now)throw new SieveFailure();if($p['public']['emits']&&!$this->config->getSystemValueBool('app.mail.mcp-transmission.enabled',false))throw new SieveFailure();$this->store->coordinator()->scope()->assertOwned();};
                $this->store->insert($r,$authorize);$r=$this->store->advance($r,'put_attempting',$authorize);$authorize();$c->installScript($candidate,$p['candidate']['script']);if(hash('sha256',$c->getScript($candidate))!==$r['candidate_hash'])throw new SieveFailure();$r=$this->store->advance($r,'installed',$authorize);
                $check=$this->snapshot($c);if($check['name']!==$old['name']||$check['hash']!==$old['hash'])throw new SieveFailure();$r=$this->store->advance($r,'activation_attempting',$authorize);$authorize();$c->setActive($candidate);
                $check=$this->snapshot($c);if($check['name']!==$candidate||$check['hash']!==$r['candidate_hash'])throw new SieveFailure();$r=$this->store->advance($r,'active_observed',$authorize);
                $authorize();$fresh=$this->accounts->find($uid,$id)->getMailAccount();if((bool)$fresh->getOutOfOfficeFollowsSystem()!==$p['following']){$fresh->setOutOfOfficeFollowsSystem($p['following']);$this->accountMapper->updateSieveFollowing($fresh,$fence,SieveStore::origin($r));}
                $fence->authorize($uid,SieveStore::origin($r));[$finalAccount,$finalRecipients,$finalProfile]=$this->settings($uid,$id,$fence);$r=$this->store->advance($r,'completed',fn()=>$fence->authorize($uid,SieveStore::origin($r)),$finalProfile);$fence->authorize($uid,SieveStore::origin($r));return SieveStore::receipt($r);
            }catch(\Throwable){if($r!==null){$persisted=$this->store->replay($uid,$key);if($persisted!==null){$fence->authorize($uid,SieveStore::origin($persisted));return SieveStore::receipt($persisted);}}throw new SieveFailure();}finally{$c->close();}
        });
    }
    public function accessContext(string $uid,string $id):array{return SieveStore::origin($this->store->find($uid,$id));}
    public function findByOperation(string $uid,string $key):?array {$r=$this->store->replay($uid,$key);return $r===null?null:SieveStore::receipt($r);}
    public function status(string $uid,string $id,object $fence):array {
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id,$fence){$r=$this->store->find($uid,$id);$fence->authorize($uid,SieveStore::origin($r));$out=SieveStore::receipt($r);[$a,$recipients,$profile]=$this->settings($uid,(int)$r['account_id'],$fence);if($profile!==($r['state']==='completed'?$r['observed_profile']:$r['profile'])){$fence->authorize($uid,SieveStore::origin($r));return $out+['observation'=>'profile_changed'];}$c=$this->clients->getGuardedClient($a);try{$s=$this->snapshot($c);$match=$s['name']===$r['candidate']&&$s['hash']===$r['candidate_hash'];$present=in_array($r['candidate'],$s['names'],true)&&hash('sha256',$c->getScript($r['candidate']))===$r['candidate_hash'];$fence->authorize($uid,SieveStore::origin($r));return $out+['observation'=>$match?'candidate_active':($s['name']===$r['original_name']&&$s['hash']===$r['original_hash']?'original_active':'other_active'),'candidate_present'=>$present,'following_system'=>(bool)$a->getMailAccount()->getOutOfOfficeFollowsSystem(),'history_proven'=>false];}finally{$c->close();}});
    }
}
