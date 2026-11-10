<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Authorization;
use OCA\Mail\Exception\DraftAdmissionFailure;
/** Bounded, credential-free handoff policy, independent of HTTP response success. */
final class SetupContext {
    public readonly array $row;
    private readonly array $intent;
    public function __construct(array $row) {
        foreach(['id','source_key'] as $key)if(!is_string($row[$key]??null)||preg_match('/^[a-f0-9]{64}$/D',$row[$key])!==1)throw new DraftAdmissionFailure('Invalid native setup identity.');
        if(!is_string($row['user_id']??null)||$row['user_id']===''||strlen($row['user_id'])>1024
            ||!in_array($row['kind']??null,['create_account','configure_connection'],true)
            ||!in_array($row['phase']??null,['registered','active','proved','consumed'],true))throw new DraftAdmissionFailure('Invalid native setup context.');
        foreach(['created_at','expires_at'] as $key){$value=filter_var($row[$key]??null,FILTER_VALIDATE_INT);if($value===false||$value<0)throw new DraftAdmissionFailure('Invalid native setup lifetime.');$row[$key]=$value;}
        if($row['expires_at']<$row['created_at']||$row['expires_at']-$row['created_at']>86400)throw new DraftAdmissionFailure('Native setup exceeds its lifetime.');
        if(($row['target_id']??null)!==null){$target=filter_var($row['target_id'],FILTER_VALIDATE_INT);if($target===false||$target<1)throw new DraftAdmissionFailure('Invalid native setup target.');$row['target_id']=$target;}else$row['target_id']=null;
        if($row['kind']==='configure_connection'&&$row['target_id']===null)throw new DraftAdmissionFailure('An existing setup target is required.');
        $raw=$row['intent_json']??null;
        if(!is_string($raw)||strlen($raw)>16384)throw new DraftAdmissionFailure('Native setup intent exceeds its bound.');
        try{$intent=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DraftAdmissionFailure('Invalid native setup intent.');}
        if(!is_array($intent))throw new DraftAdmissionFailure('Invalid native setup intent.');
        $this->intent=self::normalizeIntent($intent);
        $this->row=array_intersect_key($row,array_flip(['id','source_key','user_id','kind','target_id','phase','activated_at','created_at','expires_at','proof_revision','proof_kind','proof_protocols','proof_at','consumed_by']));
    }
    public static function sourceKey(string $uid,string $scope,string $operation):string {
        if($uid===''||strlen($uid)>1024||preg_match('/^[a-f0-9]{64}$/D',$scope)!==1||preg_match('/^[A-Za-z0-9_.-]{1,128}$/D',$operation)!==1)throw new DraftAdmissionFailure('Invalid native setup source.');
        return hash('sha256',"native-setup-v7\\0".$uid."\\0".$scope."\\0".$operation);
    }
    public static function normalizeIntent(array $intent):array {
        if(array_diff(array_keys($intent),['name','email','auth_method','imap','smtp'])!==[])throw new DraftAdmissionFailure('Only non-secret native setup intent is accepted.');
        foreach(['name'=>255,'email'=>500,'auth_method'=>16] as $key=>$limit)if(array_key_exists($key,$intent))self::text($intent[$key],$limit);
        if(isset($intent['email'])&&!filter_var($intent['email'],FILTER_VALIDATE_EMAIL))throw new DraftAdmissionFailure('Invalid native setup address.');
        if(isset($intent['auth_method'])&&!in_array($intent['auth_method'],['password','xoauth2'],true))throw new DraftAdmissionFailure('Unsupported native authentication method.');
        foreach(['imap','smtp'] as $protocol)if(array_key_exists($protocol,$intent)){
            $p=$intent[$protocol];if(!is_array($p)||array_diff(array_keys($p),['host','port','tls','user'])!==[])throw new DraftAdmissionFailure('Only non-secret native connection intent is accepted.');
            foreach(['host'=>255,'user'=>500,'tls'=>4] as $key=>$limit)if(array_key_exists($key,$p)){self::text($p[$key],$limit);if($p[$key]==='')throw new DraftAdmissionFailure('Empty native connection intent.');}
            if(isset($p['port'])&&(!is_int($p['port'])||$p['port']<1||$p['port']>65535))throw new DraftAdmissionFailure('Invalid native connection port.');
            if(isset($p['tls'])&&!in_array($p['tls'],['ssl','tls'],true))throw new DraftAdmissionFailure('Native evidence requires TLS.');
            ksort($p);$intent[$protocol]=$p;
        }
        ksort($intent);if(strlen(json_encode($intent,JSON_THROW_ON_ERROR))>16384)throw new DraftAdmissionFailure('Native setup intent exceeds its bound.');return $intent;
    }
    private static function text(mixed $value,int $limit):void {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$limit||preg_match('/[\x00-\x1f\x7f]/u',$value))throw new DraftAdmissionFailure('Invalid native setup text.');
    }
    public function availableForOwner(string $uid,int $now):bool {
        return $this->row['user_id']===$uid&&$this->row['created_at']<=$now&&$this->row['expires_at']>=$now&&$this->row['phase']!=='consumed';
    }
    public function allows(string $uid,string $kind,int $accountId,array $profile,int $now):bool {
        if(!$this->availableForOwner($uid,$now)||$this->row['phase']==='registered'||($this->row['activated_at']??null)===null||$this->row['kind']!==$kind||$accountId<1)return false;
        if($this->row['target_id']!==null&&$this->row['target_id']!==$accountId)return false;
        foreach(['imap','smtp'] as $protocol)if(!in_array($profile[$protocol]['tls']??null,['ssl','tls'],true))return false;
        return self::matches($this->intent,$profile);
    }
    private static function matches(array $intent,array $profile):bool {
        foreach($intent as $key=>$value)if(!array_key_exists($key,$profile)||(is_array($value)?(!is_array($profile[$key])||!self::matches($value,$profile[$key])):$value!==$profile[$key]))return false;
        return true;
    }
    public function intent():array {return $this->intent;}
}
