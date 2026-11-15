<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
/** Deny-only ephemeral claims. They hold no authority and never make durable uncertain intents replayable. */
final class LocalEventState {
    /** @var array<string,string> Request/process-local tombstones, never released or serialized. */
    private static array $claimed=[];
    private int $status=0;
    private bool $verified=false;
    private bool $resultClaimed=false;
    private bool $poisoned=false;
    public function __construct(private readonly string $intent) {
        if(!preg_match('/^[a-f0-9]{64}$/D',$intent)){throw new \LogicException('Circle admission key is malformed');}
    }
    /** Claim before callbacks, including constructors/resolvers reached by the verification closure. */
    public function admit(string $intent,array $binding,callable $verify):mixed {
        if(!array_is_list($binding)||$binding===[]||count($binding)>16){$this->poison();throw new \LogicException('Circle event binding is malformed');}
        foreach($binding as$value){if((!is_string($value)&&!is_int($value))||(is_string($value)&&strlen($value)>8192)){$this->poison();throw new \LogicException('Circle event binding is not bounded scalar data');}}
        if($this->poisoned||$intent!==$this->intent||isset(self::$claimed[$intent])||count(self::$claimed)>=512){$this->poison();throw new \LogicException('Circle intent verification cannot replay');}
        self::$claimed[$intent]=hash('sha256',json_encode($binding,JSON_THROW_ON_ERROR));
        try {
            $value=$verify();
            if($this->poisoned){throw new \LogicException('Circle verification was poisoned by nested admission');}
            $this->verified=true;
            return $value;
        } catch(\Throwable $error){$this->poison();throw $error;}
    }
    public function status():int {return $this->status;}
    public function claimManage():void {
        if($this->poisoned||!$this->verified||$this->status!==0){throw new \LogicException('Circle manage requires one verified admission');}
        $this->status=1;
    }
    public function done():void {
        if($this->poisoned||$this->status!==1||$this->resultClaimed){throw new \LogicException('Circle DONE requires claimed manage');}
        $this->status=8;
    }
    public function claimResult(string $instance,mixed $payload):array {
        if($this->poisoned||$this->status!==8||$this->resultClaimed||$instance===''){throw new \LogicException('Circle result requires unclaimed native DONE');}
        $this->resultClaimed=true;
        return [$instance=>$payload];
    }
    public function over():void {
        if($this->poisoned||$this->status!==8||!$this->resultClaimed){throw new \LogicException('Circle OVER requires a claimed result');}
        $this->status=9;
    }
    public function poison():void {$this->poisoned=true;}
    public function __serialize():array {throw new \LogicException('Circle event phase is not continuation authority');}
    private function __clone() {}
}
