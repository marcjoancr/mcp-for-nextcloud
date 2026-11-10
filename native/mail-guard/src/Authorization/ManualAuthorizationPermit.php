<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Authorization;
use OCA\Mail\Exception\DraftAdmissionFailure;
/** Request-local acceptance; refresh/error paths never receive a successful permit. */
final class ManualAuthorizationPermit implements \JsonSerializable {
    private ?array $accepted=null;
    public readonly string $attemptId;
    public function __construct(
        public readonly string $uid,
        public readonly string $contextId,
        public readonly int $accountId,
        public readonly string $stateHash,
        ?string $attemptId=null,
    ) {
        $this->attemptId=$attemptId??bin2hex(random_bytes(32));
        if(preg_match('/^[a-f0-9]{64}$/D',$this->attemptId)!==1||$uid===''||strlen($uid)>1024||$accountId<1||preg_match('/^[a-f0-9]{64}$/D',$contextId)!==1||preg_match('/^[a-f0-9]{64}$/D',$stateHash)!==1)throw new DraftAdmissionFailure('Invalid native manual authorization context.');
    }
    private function owned(object $account):bool {
        return $account->getUserId()===$this->uid&&$account->getId()===$this->accountId;
    }
    /** Native encrypted values stay private in this request; never persist or expose their hash. */
    private function values(#[\SensitiveParameter] object $account):?array {
        if(!$this->owned($account))return null;
        $entity=$account->getMailAccount();$access=$entity->getOauthAccessToken();$refresh=$entity->getOauthRefreshToken();$ttl=$entity->getOauthTokenTtl();
        if(!is_string($access)||$access===''||!is_string($refresh)||$refresh===''||!is_int($ttl))return null;
        return [$access,$refresh,$ttl];
    }
    /** Called only after the pinned manual provider success branch set its new native values. */
    public function accept(#[\SensitiveParameter] object $account):void {
        if($this->accepted!==null||($values=$this->values($account))===null)throw new DraftAdmissionFailure('Native manual authorization was not accepted for this account.');
        $this->accepted=$values;
    }
    public function isAccepted():bool {return $this->accepted!==null;}
    public function matchesPersisted(#[\SensitiveParameter] object $account):bool {
        if($this->accepted===null||($values=$this->values($account))===null)return false;
        return hash_equals($this->accepted[0],$values[0])&&hash_equals($this->accepted[1],$values[1])&&$this->accepted[2]===$values[2];
    }
    public function jsonSerialize():array {return ['accepted'=>$this->isAccepted()];}
    public function __debugInfo():array {return $this->jsonSerialize();}
    public function __serialize():array {throw new \LogicException('Native manual authorization permits cannot be persisted.');}
}
