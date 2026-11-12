<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Mail\Presentation\MailRows;
use OCP\IDBConnection;
/** Native dirty-field tracking preserves omitted signature/certificate/account fields. */
class MailAliasGateway {
    public function __construct(private MailSettingsGateway $accounts,private MailServices $native,private MailAliasBoundary $boundary,
        private OperationsMapper $operations,private PlanState $states,private IDBConnection $db,private ?\OCA\Mcp\Modules\Mail\Access\MailFreshAccess $freshAccess=null){}
    private function mapper(string $uid):object{return $this->native->newAliasMapper($uid,$this->db);}
    private function entity(string $uid,int $account,int $alias):object {
        $this->accounts->account($uid,$account);
        try{$a=$this->mapper($uid)->find($alias,$uid);}catch(\Throwable){throw new BeforeEffectFailure('Mail identity not found.');}
        if((int)$a->getAccountId()!==$account||$a->getProvisioningId()!==null)throw new BeforeEffectFailure('Mail identity is unavailable or provisioned.');
        return $a;
    }
    private function row(object $a,string $uid):array {
        $public=['account_id'=>(int)$a->getAccountId(),'alias_id'=>(int)$a->getId(),'name'=>(string)$a->getName(),'email'=>(string)$a->getAlias()];
        $revision=$this->states->of('mail_alias_revision',$uid,[$public,$a->getSignature(),$a->getSignatureMode(),$a->getSmimeCertificateId(),$a->getProvisioningId()]);
        return $public+['revision'=>$revision];
    }
    public function read(string $uid,int $account,int $alias):array{return $this->row($this->entity($uid,$account,$alias),$uid);}
    public function prepare(string $uid,array $args,string $action):array {
        $this->boundary->requireSupported();$id=(int)$args['account_id'];$profile=$this->accounts->readConnection($uid,$id);
        if(!$profile['editable'])throw new BeforeEffectFailure('Provisioned identities must be managed by the administrator.');
        if($this->operations->unresolvedAccount($uid,$id,$args['operation_id']??''))throw new BeforeEffectFailure('Inspect active, partial or uncertain account operations before changing identities.');
        if(!in_array($action,['add','update','delete'],true))throw new \InvalidArgumentException('Unsupported identity action.');
        $before=$action==='add'?null:$this->read($uid,$id,(int)$args['alias_id']);
        $changes=$action==='add'?['name'=>$args['name']??null,'email'=>$args['email']??null]:($action==='update'?($args['changes']??[]):[]);
        if(!is_array($changes)||($action!=='delete'&&$changes===[])||array_diff(array_keys($changes),['name','email'])!==[])throw new \InvalidArgumentException('Only alias name and email may be changed.');
        foreach($changes as $key=>$value){
            if(!is_string($value)||strlen($value)>($key==='name'?255:254)||MailRows::text($value)!==$value||preg_match('/[\x00-\x1f\x7f]/u',$value))throw new \InvalidArgumentException('Choose bounded literal identity text.');
            if($key==='email'&&filter_var($value,FILTER_VALIDATE_EMAIL)===false)throw new \InvalidArgumentException('Choose a valid alias email address.');
        }
        $after=$action==='delete'?null:array_replace($before??['account_id'=>$id,'alias_id'=>null],$changes);
        if($after!==null)unset($after['revision']);
        $inventory=[];$rows=$this->mapper($uid)->findAll($id,$uid);
        if(count($rows)>1000||($action==='add'&&count($rows)>=1000))throw new BeforeEffectFailure('The alias inventory is at its supported limit. Use native Mail.');
        foreach($rows as $a){
            if(count($inventory)>=1000)throw new BeforeEffectFailure('The alias inventory exceeds the supported bound.');
            if($action==='add'&&strcasecmp((string)$a->getAlias(),$changes['email'])===0)throw new BeforeEffectFailure('An alias with this address exists. Creation never adopts another native identity.');
            $inventory[]=[(int)$a->getId(),(string)$a->getAlias(),(string)$a->getName()];
        }
        usort($inventory,static fn(array $a,array $b):int=>$a[0]<=>$b[0]);
        if($action==='delete')foreach([$this->native->localDraftType($uid),$this->native->localOutgoingType($uid)] as $type){
            if($this->native->scopedMetadataAvailable())$referenced=$this->native->boundedMetadata($uid)->hasLocalMessages($uid,$id,$type,(int)$args['alias_id']);
            else {
                if(!$this->accounts->unrestricted($uid))throw new BeforeEffectFailure('Scoped local inventory requires the current native dependency.');
                $referenced=array_filter($this->native->localMessageMapper($uid)->getAllForUser($uid,$type),static fn(object $message):bool=>(int)$message->getAliasId()===(int)$args['alias_id'])!==[];
            }
            if($referenced)throw new BeforeEffectFailure('This identity is referenced by a local draft or outgoing item. Review it in native Mail first.');
        }
        return ['account_id'=>$id,'alias_id'=>$args['alias_id']??null,'before'=>$before,'after'=>$after,
            'revision'=>$this->states->of('mail_alias_inventory',$uid,[$profile['revision'],$inventory])];
    }
    /** Must run only in the shared journal transaction, after a fresh prepared revision check. */
    public function apply(string $uid,array $args,string $action):int {
        $this->freshAccess?->assertActive($uid);
        $id=(int)$args['account_id'];$this->accounts->account($uid,$id);$mapper=$this->mapper($uid);
        if($action==='add'){
            $a=$this->native->newAlias($uid);$a->setAccountId($id);$a->setAlias($args['email']);$a->setName($args['name']);
            return (int)$mapper->insert($a)->getId();
        }
        $a=$this->entity($uid,$id,(int)$args['alias_id']);
        if($action==='delete'){$mapper->delete($a);return (int)$a->getId();}
        if(array_key_exists('name',$args['changes']))$a->setName($args['changes']['name']);
        if(array_key_exists('email',$args['changes']))$a->setAlias($args['changes']['email']);
        $mapper->update($a);return (int)$a->getId();
    }
}
