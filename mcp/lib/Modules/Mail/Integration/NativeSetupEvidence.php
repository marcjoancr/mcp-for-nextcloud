<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCP\{IDBConnection,IConfig};
/** Closed metadata-only adapter. No credential arguments, provider exchanges or native clients. */
class NativeSetupEvidence {
    public function __construct(private MailServices $services,private MailNativeBoundary $boundary,private MailCredentialStorage $storage,private IDBConnection $db,private IConfig $config){}
    public function available():bool {
        return MailNativeBoundary::CREDENTIAL_WRITES_QUALIFIED&&$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))&&$this->boundary->credentialsSourceVerified()&&$this->storage->verified();
    }
    private function service(string $uid):object {
        if(!$this->available())throw new BeforeEffectFailure('Native credential evidence is not qualified for this installation.');
        $s=$this->services->setupEvidence($uid);$s->assertDatabase($this->db);return $s;
    }
    public function register(string $uid,string $scope,string $source,string $kind,?int $account,array $intent,int $expires):string {
        $s=$this->service($uid);
        try{$id=$s->register($uid,$scope,$source,$kind,$account,$intent,$expires);}
        catch(\Throwable $e){if(is_a($e,'OCA\\Mail\\Exception\\DraftAdmissionFailure'))throw new BeforeEffectFailure('Native setup admission is unavailable.');throw $e;}
        if(!is_string($id)||preg_match('/^[a-f0-9]{64}$/D',$id)!==1)throw new \RuntimeException('Native setup admission could not be verified.');
        return $id;
    }
    public function observe(string $uid,string $id,int $account,string $scope,string $source):?array {
        $p=$this->service($uid)->observe($uid,$id,$account,$scope,$source);
        if($p===null)return null;
        if(!is_array($p)||array_diff(array_keys($p),['status','account_id','revision','kind','protocols','saved_at','credential_change'])!==[]
            ||($p['status']??null)!=='native_write_observed'||($p['account_id']??null)!==$account||!is_int($p['revision']??null)||$p['revision']<1
            ||!in_array($p['kind']??null,['password','google','microsoft'],true)||!in_array($p['protocols']??null,[['imap'],['smtp'],['imap','smtp']],true)
            ||!is_int($p['saved_at']??null)||$p['saved_at']<1||($p['credential_change']??null)!=='unverified')throw new \RuntimeException('Native evidence response is invalid.');
        return $p;
    }
    public function describe(string $uid,string $id,string $scope,string $source):?array {
        $p=$this->service($uid)->describe($uid,$id,$scope,$source);
        if($p===null)return null;
        if(!is_array($p)||array_diff(array_keys($p),['kind','target_account_id','expires_at'])!==[]
            ||!in_array($p['kind']??null,['create_account','configure_connection'],true)||!array_key_exists('target_account_id',$p)
            ||($p['target_account_id']!==null&&(!is_int($p['target_account_id'])||$p['target_account_id']<1))||!is_int($p['expires_at']??null))throw new \RuntimeException('Native handoff response is invalid.');
        return $p;
    }
    public function withCompletion(string $uid,\Closure $work):mixed {
        if($this->db->inTransaction())throw new BeforeEffectFailure('Native completion cannot inherit another transaction.');
        return $this->service($uid)->withOwner($uid,$work);
    }
    private function shared(IDBConnection $db,string $uid):object {
        if($this->db!==$db)throw new BeforeEffectFailure('Native completion requires the authoritative primary connection.');
        $s=$this->service($uid);$s->assertDatabase($db);return $s;
    }
    public function claim(IDBConnection $db,string $uid,string $id,int $account,int $revision,string $scope,string $source,string $completion):void {
        $this->shared($db,$uid)->claim($uid,$id,$account,$revision,$scope,$source,$completion);
    }
    public function assertCompletion(IDBConnection $db,string $uid):void {$this->shared($db,$uid)->assertCompletion($db,$uid);}
}
