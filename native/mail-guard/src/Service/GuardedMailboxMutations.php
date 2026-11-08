<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Db\{Mailbox,MailboxMapper};
use OCA\Mail\IMAP\IMAPClientFactory;
use OCP\{IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
/** Targeted native folder metadata only. Never invokes whole-account sync, drafts, outbox or SMTP. */
final class GuardedMailboxMutations {
    public function __construct(private AccountService $accounts,private MailboxMapper $boxes,private IMAPClientFactory $clients,private IDBConnection $db,private IConfig $config,private IEventDispatcher $events){}
    public function metadata(string $owner,int $id):array {
        $account=$this->accounts->find($owner,$id);if($account->getUserId()!==$owner)throw new \RuntimeException('Account unavailable.');$e=$account->getMailAccount();$rows=$this->boxes->findAll($account);if(count($rows)>1000)throw new \RuntimeException('Native folder inventory exceeds limit.');$roles=[];
        foreach(['Drafts','Sent','Trash','Archive','Junk','Snooze'] as $role){$v=$e->{'get'.$role.'MailboxId'}();if($v!==null)$roles[]=(int)$v;}sort($roles,SORT_NUMERIC);$roles=array_values(array_unique($roles));$out=[];
        foreach($rows as $b){$tokens=[$b->getSyncNewToken(),$b->getSyncChangedToken(),$b->getSyncVanishedToken()];$gens=[];foreach($tokens as $token){$v=is_string($token)?base64_decode($token,true):false;$gens[]=$v!==false&&preg_match('/(?:^|,)V([1-9][0-9]*)(?:,|$)/D',$v,$match)?$match[1]:null;}$generation=count(array_unique($gens,SORT_REGULAR))===1?$gens[0]:null;
            $attrs=json_decode($b->getAttributes()??'[]',true,16,JSON_THROW_ON_ERROR);$special=json_decode($b->getSpecialUse()??'[]',true,16,JSON_THROW_ON_ERROR);sort($special);$out[]=['id'=>(int)$b->getId(),'name'=>$b->getName(),'delimiter'=>$b->getDelimiter(),'selectable'=>$b->getSelectable()===true,'shared'=>$b->isShared()!==false,'special'=>$special,'subscribed'=>in_array('\\subscribed',array_map('strtolower',$attrs),true),'generation'=>$generation,'cache_revision'=>hash('sha256',json_encode($tokens,JSON_THROW_ON_ERROR))];}
        usort($out,static fn(array $a,array $b):int=>$a['id']<=>$b['id']);return ['namespace'=>$e->getPersonalNamespace(),'roles'=>$roles,'folders'=>$out];
    }
    public function revision(array $m):string {foreach($m['folders'] as &$b){unset($b['cache_revision'],$b['generation']);}unset($b);return hash('sha256',json_encode($m,JSON_THROW_ON_ERROR));}
    private function target(string $owner,array $r):void {
        $m=$this->metadata($owner,$r['account_id']);if($this->revision($m)!==$r['inventory_revision'])throw new \RuntimeException('Native folder inventory changed.');
        $indexed=[];foreach($m['folders'] as $b)$indexed[$b['id']]=$b;foreach($r['tree'] as $b)if(($indexed[$b['id']]??null)!==$b)throw new \RuntimeException('Native folder identity changed.');
    }
    public function mutate(string $owner,array $r,MutationFence $fence):array {
        $this->target($owner,$r);$a=$this->accounts->find($owner,$r['account_id']);if(!$this->config->getSystemValueBool('app.mail.verify-tls-peer',true)||$this->config->getSystemValueBool('app.mail.debug',false)||$a->getMailAccount()->getDebug())throw new \RuntimeException('Unsafe native transport policy.');
        $c=$this->clients->getClient($a,false);
        try{
            $personal=null;$found=false;
            foreach($c->getNamespaces([],['ob_return'=>true]) as $namespace){
                if($namespace->type===\Horde_Imap_Client::NS_PERSONAL&&!$found){$personal=$namespace->name!==''?$namespace->name:null;$found=true;}
                if(in_array($namespace->type,[\Horde_Imap_Client::NS_OTHER,\Horde_Imap_Client::NS_SHARED],true)&&$namespace->name!==''&&(str_starts_with($r['name'],$namespace->name)||str_starts_with($r['new_name'],$namespace->name)))throw new \RuntimeException('Non-personal native namespace.');
            }
            if(!$found||$personal!==$a->getMailAccount()->getPersonalNamespace())throw new \RuntimeException('Native personal namespace changed.');
            $remote=$c->mutateMailboxGuarded($r,$fence);
        }finally{$c->logout();}
        if(!$fence->beforeCache($r))throw new \RuntimeException('Folder cache admission revoked.');$this->db->beginTransaction();
        try{
            $locks=[['mail_accounts',$r['account_id']]];$ids=array_column($r['tree'],'id');sort($ids,SORT_NUMERIC);foreach($ids as $id)$locks[]=['mail_mailboxes',$id];
            foreach($locks as [$table,$id]){$q=$this->db->getQueryBuilder();$q->select('id')->from($table)->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->forUpdate();$result=$q->executeQuery();try{if($result->fetchOne()===false)throw new \RuntimeException('Folder cache row disappeared.');}finally{$result->closeCursor();}}
            $this->target($owner,$r);$current=$this->boxes->findAll($a);$byName=[];foreach($current as $b)$byName[$b->getName()]=$b;$cached=[];
            foreach($remote['proof']['folders'] as $p){
                if($r['action']==='create_mailbox'){
                    if(isset($byName[$p['name']]))throw new \RuntimeException('Created cache identity is ambiguous.');$b=new Mailbox();$b->setAccountId($r['account_id']);$b->setName($p['name']);$b->setNameHash(md5($p['name']));$b->setDelimiter($r['delimiter']);$b->setSelectable(true);$b->setShared(false);$b->setSpecialUse('[]');$b->setMessages(0);$b->setUnseen(0);
                }else{
                    $oldName=$r['action']==='rename_mailbox'?$r['name'].substr($p['name'],strlen($r['new_name'])):$p['name'];$b=$byName[$oldName]??null;if($b===null||($oldName!==$p['name']&&isset($byName[$p['name']])))throw new \RuntimeException('Renamed cache identity is ambiguous.');
                    if($r['action']==='rename_mailbox'){$b->setName($p['name']);$b->setNameHash(md5($p['name']));}
                }
                $b->setAttributes(json_encode($remote['attributes'][$p['name']],JSON_THROW_ON_ERROR));$b=$r['action']==='create_mailbox'?$this->boxes->insert($b):$this->boxes->update($b);
                $verified=$this->boxes->find($a,$p['name']);if($verified->getId()!==$b->getId())throw new \RuntimeException('Folder cache readback changed.');$cached[]=['id'=>(int)$verified->getId(),'name'=>$verified->getName()];
            }
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
        $this->events->dispatch(\OCA\Mail\Events\MailboxesSynchronizedEvent::class,new \OCA\Mail\Events\GuardedMailboxesSynchronizedEvent($a));
        $proof=['state'=>'updated','account_id'=>$r['account_id'],'mailboxes'=>$cached];if(!$fence->recordCache($proof))throw new \RuntimeException('Folder cache completion not recorded.');return ['remote'=>$remote['proof'],'cache'=>$proof];
    }
}
