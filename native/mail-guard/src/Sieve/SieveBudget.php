<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Sieve;
/** One fresh session, including connect, TLS, greeting, SASL and every terminal response. */
final class SieveBudget {
    public const SCRIPT=262144, TOTAL=1048576, LINE=8192, LINES=1024, COMMANDS=32, NAMES=100, NAME=255, CAPABILITIES=64;
    private int $started;private int $in=0,$out=0,$lines=0,$commands=0;
    public function __construct(){$this->started=hrtime(true);}
    public function remaining():float {$s=17-(hrtime(true)-$this->started)/1e9;if($s<=0)throw new SieveFailure();return $s;}
    public function inbound(int $n):void {$this->remaining();if($n<0||$n>self::TOTAL-$this->in)throw new SieveFailure();$this->in+=$n;}
    public function capacity():int {$this->remaining();if($this->in>=self::TOTAL)throw new SieveFailure();return self::TOTAL-$this->in;}
    public function outbound(int $n):void {$this->remaining();if($n<0||$n>self::TOTAL-$this->out)throw new SieveFailure();$this->out+=$n;}
    public function line():void {if(++$this->lines>self::LINES)throw new SieveFailure();$this->remaining();}
    public function command():void {if(++$this->commands>self::COMMANDS)throw new SieveFailure();$this->remaining();}
    public function literal(string $decimal,int $limit):int {if(preg_match('/^(0|[1-9][0-9]{0,6})$/D',$decimal)!==1)throw new SieveFailure();$n=(int)$decimal;if($n>$limit||$n>$this->capacity()-2)throw new SieveFailure();return $n;}
}
