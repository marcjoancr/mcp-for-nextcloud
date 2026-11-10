<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Authorization;
use OCA\Mail\Account;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Drafts\{DraftCoordinator,DraftScope};
use OCA\Mail\Exception\DraftAdmissionFailure;
use OCP\{IDBConnection,IConfig,IRequest};
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Native manual-write evidence. No API here submits credentials or returns a native client. */
final class SetupEvidence {
    public const COOKIE='mcp_mail_setup';
    private const CONTEXTS='mail_mcp_auth_contexts';
    private const STATES='mail_mcp_auth_states';
    public function __construct(private IDBConnection $db,private IConfig $config,private ITimeFactory $clock,private IRequest $request){}
    public function assertDatabase(IDBConnection $db):void {if($this->db!==$db)throw new DraftAdmissionFailure('Native setup requires the same primary transaction.');}
    private function primary():void {
        $provider=$this->db->getDatabaseProvider();if(!in_array($provider,[IDBConnection::PLATFORM_MYSQL,IDBConnection::PLATFORM_POSTGRES],true)||$this->config->getSystemValue('dbreplica',[])!==[]||$this->config->getSystemValue('dbsharding',[])!==[])throw new DraftAdmissionFailure('Native setup evidence requires primary transactional storage.');
        $s=$this->db->createSchema();$prefix=$this->config->getSystemValueString('dbtableprefix','oc_');
        if($provider===IDBConnection::PLATFORM_MYSQL)foreach(['mail_accounts',self::CONTEXTS,self::STATES] as $name)if(strtolower((string)($s->getTable($prefix.$name)->getOptions()['engine']??''))!=='innodb')throw new DraftAdmissionFailure('Native setup participants must be transactional.');
    }
    public function withOwner(string $uid,\Closure $work):mixed {
        $this->primary();return DraftCoordinator::forDatabase($this->db)->runOwner($uid,static fn(DraftScope $scope):mixed=>$work());
    }
    private function atomic(string $uid,\Closure $work):mixed {
        return $this->withOwner($uid,function()use($work):mixed {
            $guard=DraftCoordinator::forDatabase($this->db);$guard->assertNoTransaction();$scope=$guard->scope();$this->db->beginTransaction();
            try{$result=$work($scope);$scope->assertOwned();$this->db->commit();return $result;}catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
        });
    }
    private function parameter(mixed $value):mixed {return $value===null?IQueryBuilder::PARAM_NULL:(is_bool($value)?IQueryBuilder::PARAM_BOOL:(is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));}
    private function select(string $table,array $where,array $columns=['*'],bool $lock=false):?array {
        $q=$this->db->getQueryBuilder();$q->select(...$columns)->from($table);
        foreach($where as $key=>$value)$q->andWhere($q->expr()->eq($key,$q->createNamedParameter($value,$this->parameter($value))));
        if($lock)$q->forUpdate();$r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}return $row===false?null:$row;
    }
    private function insert(string $table,array $row,DraftScope $scope):void {
        $q=$this->db->getQueryBuilder();$q->insert($table);
        foreach($row as $key=>$value)$q->setValue($key,$q->createNamedParameter($value,$this->parameter($value)));
        $scope->markWrite();$q->executeStatement();
    }
    private function change(string $table,array $where,array $values,DraftScope $scope):void {
        $q=$this->db->getQueryBuilder();$q->update($table);
        foreach($values as $key=>$value)$q->set($key,$q->createNamedParameter($value,$this->parameter($value)));
        foreach($where as $key=>$value)$q->andWhere($q->expr()->eq($key,$q->createNamedParameter($value,$this->parameter($value))));
        $scope->markWrite();if($q->executeStatement()!==1)throw new \RuntimeException('Native setup transition could not be verified.');
    }
    private function source(string $uid,string $scope,string $operation):string {
        return SetupContext::sourceKey($uid,$scope,$operation);
    }
    private function context(string $uid,string $id,bool $lock=false):SetupContext {
        if(preg_match('/^[a-f0-9]{64}$/D',$id)!==1)throw new DraftAdmissionFailure('Native setup context unavailable.');
        $row=$this->select(self::CONTEXTS,['id'=>$id,'user_id'=>$uid],['*'],$lock);
        if($row===null)throw new DraftAdmissionFailure('Native setup context unavailable.');
        $c=new SetupContext($row);if(!$c->availableForOwner($uid,$this->clock->getTime()))throw new DraftAdmissionFailure('Native setup context unavailable.');return $c;
    }
    private function account(string $uid,int $id,bool $private=false,bool $lock=false):array {
        $columns=['id','user_id','name','email','inbound_host','inbound_port','inbound_ssl_mode','inbound_user','outbound_host','outbound_port','outbound_ssl_mode','outbound_user','auth_method','provisioning_id','mcp_auth_revision'];
        if($private)$columns=array_merge($columns,['inbound_password','outbound_password','oauth_access_token','oauth_refresh_token','oauth_token_ttl']);
        $row=$this->select('mail_accounts',['id'=>$id,'user_id'=>$uid],$columns,$lock);
        if($row===null||$row['user_id']!==$uid||$row['provisioning_id']!==null)throw new DraftAdmissionFailure('Owned editable native account unavailable.');
        $revision=filter_var($row['mcp_auth_revision'],FILTER_VALIDATE_INT);if($revision===false||$revision<0)throw new DraftAdmissionFailure('Native authorization revision unavailable.');
        $row['mcp_auth_revision']=$revision;$row['id']=(int)$row['id'];return $row;
    }
    private function profile(array $row):array {
        return ['name'=>(string)$row['name'],'email'=>(string)$row['email'],'auth_method'=>(string)$row['auth_method'],
            'imap'=>['host'=>(string)$row['inbound_host'],'port'=>(int)$row['inbound_port'],'tls'=>(string)$row['inbound_ssl_mode'],'user'=>(string)$row['inbound_user']],
            'smtp'=>['host'=>(string)$row['outbound_host'],'port'=>(int)$row['outbound_port'],'tls'=>(string)$row['outbound_ssl_mode'],'user'=>(string)$row['outbound_user']]];
    }
    public function register(string $uid,string $scope,string $operation,string $kind,?int $accountId,array $intent,int $expires):string {
        $source=$this->source($uid,$scope,$operation);$intent=SetupContext::normalizeIntent($intent);$now=$this->clock->getTime();
        if(!in_array($kind,['create_account','configure_connection'],true)||($kind==='create_account'&&$accountId!==null)||($kind==='configure_connection'&&($accountId===null||$accountId<1))||$expires<=$now||$expires-$now>86400)throw new DraftAdmissionFailure('Invalid native setup admission.');
        return $this->atomic($uid,function(DraftScope $owner)use($uid,$source,$kind,$accountId,$intent,$now,$expires):string {
            if($accountId!==null)$this->account($uid,$accountId,false,true);
            $old=$this->select(self::CONTEXTS,['user_id'=>$uid,'source_key'=>$source],['*'],true);
            if($old!==null){
                $c=new SetupContext($old);
                if($c->row['user_id']!==$uid||$c->row['kind']!==$kind||($kind==='configure_connection'&&$c->row['target_id']!==$accountId)||$c->intent()!==$intent||$c->row['expires_at']!==$expires)throw new DraftAdmissionFailure('Native setup source is already bound differently.');
                return $c->row['id'];
            }
            $q=$this->db->getQueryBuilder();$q->selectAlias($q->func()->count('*'),'n')->from(self::CONTEXTS)
                ->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->gte('expires_at',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->neq('phase',$q->createNamedParameter('consumed')));
            $r=$q->executeQuery();try{$count=(int)$r->fetchOne();}finally{$r->closeCursor();}
            if($count>=100)throw new DraftAdmissionFailure('Too many native setup handoffs are pending.');
            $id=bin2hex(random_bytes(32));
            $this->insert(self::CONTEXTS,['id'=>$id,'user_id'=>$uid,'source_key'=>$source,'kind'=>$kind,'target_id'=>$accountId,'intent_json'=>json_encode($intent,JSON_THROW_ON_ERROR),'phase'=>'registered','created_at'=>$now,'expires_at'=>$expires],$owner);
            return $id;
        });
    }
    public function activate(string $uid,string $id):array {
        return $this->atomic($uid,function(DraftScope $scope)use($uid,$id):array {
            $c=$this->context($uid,$id,true);$now=$this->clock->getTime();
            if(($c->row['activated_at']??null)===null)$this->change(self::CONTEXTS,['id'=>$id,'user_id'=>$uid],['activated_at'=>$now,'phase'=>'active'],$scope);
            return ['id'=>$id,'expires_at'=>$c->row['expires_at']];
        });
    }
    private function cookie():?string {
        // A newer tab can replace the cookie; only the originating page may admit evidence.
        $id=$this->request->getCookie(self::COOKIE);$page=$this->request->getParam('mcpSetupContext');
        return is_string($id)&&is_string($page)&&preg_match('/^[a-f0-9]{64}$/D',$id)===1&&hash_equals($id,$page)?$id:null;
    }
    private function expectedRevision(#[\SensitiveParameter] object $account,array $row):bool {
        return $account->getUserId()===$row['user_id']&&$account->getId()===$row['id']
            &&$account->getMailAccount()->getMcpAuthRevision()===$row['mcp_auth_revision']&&$row['mcp_auth_revision']>0;
    }
    private function prove(SetupContext $c,array $row,string $kind,int $protocols,DraftScope $scope):void {
        $this->change(self::CONTEXTS,['id'=>$c->row['id'],'user_id'=>$c->row['user_id']],['target_id'=>$row['id'],'phase'=>'proved','proof_revision'=>$row['mcp_auth_revision'],'proof_kind'=>$kind,'proof_protocols'=>$protocols,'proof_at'=>$this->clock->getTime()],$scope);
    }
    /** Evidence failure never changes the result of an already accepted native credential save. */
    public function passwordSaved(string $uid,#[\SensitiveParameter] object $account,string $routeKind,bool $imap,bool $smtp):bool {
        $id=$this->cookie();if($id===null||(!$imap&&!$smtp))return false;
        try{return $this->atomic($uid,function(DraftScope $scope)use($uid,$id,$account,$routeKind,$imap,$smtp):bool {
            $row=$this->account($uid,(int)$account->getId(),true,true);$c=$this->context($uid,$id,true);
            if($row['auth_method']!=='password'||!$this->expectedRevision($account,$row)||!$c->allows($uid,$routeKind,$row['id'],$this->profile($row),$this->clock->getTime()))return false;
            $entity=$account->getMailAccount();
            foreach(['inbound_password'=>[$imap,$entity->getInboundPassword()],'outbound_password'=>[$smtp,$entity->getOutboundPassword()]] as $key=>[$provided,$expected])
                if($provided&&(!is_string($expected)||!is_string($row[$key])||!hash_equals($expected,$row[$key])))return false;
            $this->prove($c,$row,'password',($imap?1:0)|($smtp?2:0),$scope);return true;
        });}catch(\Throwable){return false;}
    }
    public function createdWithoutCredentials(string $uid,#[\SensitiveParameter] object $account):bool {
        $id=$this->cookie();if($id===null)return false;
        try{return $this->atomic($uid,function(DraftScope $scope)use($uid,$id,$account):bool {
            $row=$this->account($uid,(int)$account->getId(),false,true);$c=$this->context($uid,$id,true);
            if($row['auth_method']!=='xoauth2'||!$this->expectedRevision($account,$row)||!$c->allows($uid,'create_account',$row['id'],$this->profile($row),$this->clock->getTime()))return false;
            if($c->row['target_id']===null)$this->change(self::CONTEXTS,['id'=>$id,'user_id'=>$uid],['target_id'=>$row['id']],$scope);
            return true;
        });}catch(\Throwable){return false;}
    }
    public function bindState(string $uid,int $accountId,#[\SensitiveParameter] string $state):bool {
        $id=$this->cookie();if($id===null)return false;
        if(!StateCodec::isCorrelated($state))throw new DraftAdmissionFailure('Only a fresh native state can bind manual evidence.');
        try{return $this->atomic($uid,function(DraftScope $scope)use($uid,$id,$accountId,$state):bool {
            $row=$this->account($uid,$accountId,false,true);$c=$this->context($uid,$id,true);$now=$this->clock->getTime();
            if($row['auth_method']!=='xoauth2'||$c->row['target_id']!==$accountId||!$c->allows($uid,$c->row['kind'],$accountId,$this->profile($row),$now))return false;
            // A signed state remains valid for normal native OAuth even when correlation storage is full.
            $q=$this->db->getQueryBuilder();$q->selectAlias($q->func()->count('*'),'n')->from(self::STATES)
                ->where($q->expr()->eq('context_id',$q->createNamedParameter($id)));
            $r=$q->executeQuery();try{$count=(int)$r->fetchOne();}finally{$r->closeCursor();}
            if($count>=32)return false;
            $hash=hash('sha256',$state);$old=$this->select(self::STATES,['id'=>$hash],['*'],true);
            if($old!==null)throw new DraftAdmissionFailure('This native state is already bound.');
            $this->insert(self::STATES,['id'=>$hash,'context_id'=>$id,'account_id'=>$accountId,'created_at'=>$now,'expires_at'=>min($c->row['expires_at'],$now+StateCodec::TTL),'used'=>false,'completed'=>false],$scope);
            return true;
        });}catch(\Throwable){return false;}
    }
    /** Native HMAC validation must already have succeeded in the callback controller. */
    public function beginOAuth(string $uid,int $accountId,#[\SensitiveParameter] string $state):?ManualAuthorizationPermit {
        if(!StateCodec::isCorrelated($state))return null;
        try{return $this->atomic($uid,function(DraftScope $scope)use($uid,$accountId,$state):?ManualAuthorizationPermit {
            $row=$this->account($uid,$accountId,false,true);$hash=hash('sha256',$state);$s=$this->select(self::STATES,['id'=>$hash],['*'],true);$now=$this->clock->getTime();
            if($s===null||(bool)$s['used']||(int)$s['account_id']!==$accountId||(int)$s['expires_at']<$now)return null;
            $c=$this->context($uid,$s['context_id'],true);
            if($row['auth_method']!=='xoauth2'||$c->row['target_id']!==$accountId||!$c->allows($uid,$c->row['kind'],$accountId,$this->profile($row),$now))return null;
            $attempt=bin2hex(random_bytes(32));$this->change(self::STATES,['id'=>$hash],['used'=>true,'attempt_id'=>$attempt],$scope);
            return new ManualAuthorizationPermit($uid,$c->row['id'],$accountId,$hash,$attempt);
        });}catch(\Throwable){return null;}
    }
    public function oauthSaved(string $uid,#[\SensitiveParameter] object $account,?ManualAuthorizationPermit $permit,string $provider):bool {
        if($permit===null||!$permit->isAccepted()||$permit->uid!==$uid||!in_array($provider,['google','microsoft'],true))return false;
        try{return $this->atomic($uid,function(DraftScope $scope)use($uid,$account,$permit,$provider):bool {
            $row=$this->account($uid,(int)$account->getId(),true,true);$c=$this->context($uid,$permit->contextId,true);$s=$this->select(self::STATES,['id'=>$permit->stateHash],['*'],true);$now=$this->clock->getTime();
            if($s===null||!(bool)$s['used']||(bool)$s['completed']||$s['attempt_id']!==$permit->attemptId||$s['context_id']!==$c->row['id']||(int)$s['account_id']!==$row['id']||(int)$s['expires_at']<$now)return false;
            if($row['auth_method']!=='xoauth2'||!$this->expectedRevision($account,$row)||!$c->allows($uid,$c->row['kind'],$row['id'],$this->profile($row),$now))return false;
            $persisted=new Account(MailAccount::fromRow($row));if(!$permit->matchesPersisted($persisted))return false;
            $this->change(self::STATES,['id'=>$permit->stateHash],['completed'=>true],$scope);$this->prove($c,$row,$provider,3,$scope);return true;
        });}catch(\Throwable){return false;}
    }
    private function proof(SetupContext $c,array $row,string $source):?array {
        $now=$this->clock->getTime();$revision=filter_var($c->row['proof_revision']??null,FILTER_VALIDATE_INT);$at=filter_var($c->row['proof_at']??null,FILTER_VALIDATE_INT);
        $protocols=(int)($c->row['proof_protocols']??0);$kind=$c->row['proof_kind']??null;
        if(!hash_equals($c->row['source_key'],$source)||$c->row['phase']!=='proved'||$c->row['target_id']!==$row['id']||$revision===false||$revision<1||$revision!==$row['mcp_auth_revision']||$at===false||$at<$c->row['created_at']||$at>$now||$protocols<1||$protocols>3||!in_array($kind,['password','google','microsoft'],true)||!$c->allows($row['user_id'],$c->row['kind'],$row['id'],$this->profile($row),$now))return null;
        return ['status'=>'native_write_observed','account_id'=>$row['id'],'revision'=>$revision,'kind'=>$kind,'protocols'=>array_values(array_filter([($protocols&1)?'imap':null,($protocols&2)?'smtp':null])),'saved_at'=>$at,'credential_change'=>'unverified'];
    }
    /** Metadata-only recovery; neither activation nor credential/proof writes. */
    public function describe(string $uid,string $id,string $scope,string $operation):?array {
        $this->primary();
        try{
            $c=$this->context($uid,$id);
            if(!hash_equals($c->row['source_key'],$this->source($uid,$scope,$operation)))return null;
            if($c->row['target_id']!==null)$this->account($uid,$c->row['target_id']);
            return ['kind'=>$c->row['kind'],'target_account_id'=>$c->row['target_id'],'expires_at'=>$c->row['expires_at']];
        }catch(DraftAdmissionFailure){return null;}
    }
    /** Check the original physical owner session immediately before the shared SQL commit. */
    public function assertCompletion(IDBConnection $db,string $uid):void {
        $this->assertDatabase($db);$scope=DraftCoordinator::forDatabase($this->db)->scope();
        if($scope->uid!==$uid||!$this->db->inTransaction())throw new DraftAdmissionFailure('Native completion transaction changed.');
        $scope->assertOwned();
    }
    public function observe(string $uid,string $id,int $accountId,string $scope,string $operation):?array {
        $this->primary();try{return $this->proof($this->context($uid,$id),$this->account($uid,$accountId),$this->source($uid,$scope,$operation));}catch(DraftAdmissionFailure){return null;}
    }
    /** Caller already holds this owner's native scope and the exact shared primary SQL transaction. */
    public function claim(string $uid,string $id,int $accountId,int $revision,string $scope,string $operation,string $completion):void {
        $this->primary();$owner=DraftCoordinator::forDatabase($this->db)->scope();
        if($owner->uid!==$uid||!$this->db->inTransaction()||preg_match('/^[A-Za-z0-9_.-]{1,128}$/D',$completion)!==1)throw new DraftAdmissionFailure('Native proof requires its shared completion transaction.');
        $row=$this->account($uid,$accountId,false,true);$c=$this->context($uid,$id,true);$proof=$this->proof($c,$row,$this->source($uid,$scope,$operation));
        if($proof===null||$proof['revision']!==$revision)throw new DraftAdmissionFailure('The native credential evidence changed before completion.');
        $this->change(self::CONTEXTS,['id'=>$id,'user_id'=>$uid,'phase'=>'proved'],['phase'=>'consumed','consumed_by'=>$completion],$owner);
    }
}
