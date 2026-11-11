<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Operations;
use OCA\Mcp\Db\ActorIncarnationsMapper;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCP\Config\IUserConfig;
use OCP\IUserManager;
/** Immutable process admission plus fresh durable registry/witness comparisons at every fence. */
class DurableActorIdentity implements ActorIdentity {
    public const WITNESS='operation_actor_incarnation';
    private array $captured=[];
    public function __construct(private ActorIncarnationsMapper $actors,private PolicyTransaction $transaction,private IUserConfig $config,private IUserManager $users) {}
    public function capture(string $uid):string {
        if(!isset($this->captured[$uid])) {
            $token=$this->actors->get($uid);
            if($token===null) {
                // Initial enrollment permits only new operations; old rows never gain this token.
                try{$token=$this->transaction->underMutex(function()use($uid):string {
                    $existing=$this->actors->get($uid);if($existing!==null)return $existing;
                    $this->config->clearCache($uid,false);
                    if($this->users->get($uid)===null)throw new ActorRetired();
                    if($this->config->getValueString($uid,'core','deleted','false')==='true')throw new \RuntimeException('Actor deletion outcome is not yet known.');
                    return $this->rotateBeforeCreation($uid);
                });}finally{$this->config->clearCache($uid,false);}
            }
            $this->assertCurrent($uid,$token);
            $this->captured[$uid]=$token;
        }
        $this->assertCurrent($uid,$this->captured[$uid]);
        return $this->captured[$uid];
    }
    /** Creation listener calls this under PolicyTransaction before backend publication. Never update captured context. */
    public function rotateBeforeCreation(string $uid):string {
        $token=$this->actors->rotate($uid);
        if(!$this->config->setValueString($uid,'mcp',self::WITNESS,$token,false,IUserConfig::FLAG_SENSITIVE|IUserConfig::FLAG_INTERNAL))throw new \RuntimeException('Actor witness was not persisted.');
        return $token;
    }
    public function assertCurrent(string $uid,string $original):void {
        if(preg_match('/^[a-f0-9]{64}$/D',$original)!==1)throw new ActorRetired();
        $token=$this->actors->get($uid);
        $this->config->clearCache($uid,false);
        $witness=$this->config->getValueString($uid,'mcp',self::WITNESS,'');
        $deleted=$this->config->getValueString($uid,'core','deleted','false');
        // Re-read after the witness: a concurrent rotation cannot splice two generations.
        if($token===null||!hash_equals($original,$token)||!hash_equals($original,$witness)||$this->actors->get($uid)!==$token)throw new ActorRetired();
        // Core sets this before the backend can still return false. Do not turn a pending deletion into permanent consent withdrawal.
        if($deleted==='true')throw new \RuntimeException('Actor deletion outcome is not yet known.');
    }
}
