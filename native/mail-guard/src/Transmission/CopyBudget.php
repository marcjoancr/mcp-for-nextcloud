<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
use OCA\Mail\Sync\RefreshBudget;
/** Pre-tokenization copy-only response budget, installed on a new private native connection. */
final class CopyBudget {
    private int $bytes=0;
    private int $lines=0;
    private int $fetches=0;
    private int $appendProofs=0;
    private ?int $validity=null;
    private ?int $expectedUid=null;
    private ?int $fetchUid=null;
    private ?int $literalLength=null;
    private bool $fetchComplete=false;
    private int $commands=0;
    private int $appends=0;
    private int $reads=0;
    private bool $fetch=false;
    private bool $continuation=false;
    private RefreshBudget $grammar;
    public function __construct(private int $artifactBytes) {if($artifactBytes<1||$artifactBytes>41943040)throw new \RuntimeException('Sent artifact exceeds its bound.');$this->grammar=new RefreshBudget();}
    public function expectValidity(int $validity):void {if($validity<1||$validity>4294967295||($this->validity!==null&&$this->validity!==$validity))throw new \RuntimeException('Invalid expected Sent mailbox identity.');$this->validity=$validity;}
    public function expectUid(int $uid):void {if($uid<1||$uid>4294967295||$this->expectedUid!==null)throw new \RuntimeException('Invalid expected Sent UID.');$this->expectedUid=$uid;}
    public function assertReadback():void {$this->check();if($this->expectedUid===null||$this->fetches!==1||!$this->fetchComplete||$this->fetchUid!==$this->expectedUid)throw new \RuntimeException('Exact Sent UID readback is unavailable.');}
    private function validUid(string $uid):int {if(preg_match('/^[1-9][0-9]{0,9}$/D',$uid)!==1||(float)$uid>4294967295)throw new \RuntimeException('Invalid Sent UID.');$value=(int)$uid;if($this->expectedUid!==null&&$this->expectedUid!==$value)throw new \RuntimeException('Unexpected Sent UID.');return $value;}
    public function check():void {$this->grammar->check();}
    public function consume(int $bytes):void {$this->check();if($bytes<0||$bytes>$this->artifactBytes+65536-$this->bytes)throw new \RuntimeException('Sent copy wire budget exhausted.');$this->bytes+=$bytes;}
    public function beginResponse():void {$this->check();if($this->fetch&&!$this->fetchComplete)throw new \RuntimeException('Incomplete Sent FETCH response.');$this->fetch=false;$this->continuation=false;$this->grammar->beginResponse();}
    public function line(string $line):void {
        $this->check();if(strlen($line)>32768||++$this->lines>512)throw new \RuntimeException('Sent copy response bound exceeded.');
        if($this->continuation){
            if($this->fetchUid===null){if(preg_match('/^ +UID +([1-9][0-9]{0,9})\)$/iD',$line,$m)!==1)throw new \RuntimeException('Invalid Sent UID trailer.');$this->fetchUid=$this->validUid($m[1]);}
            elseif(trim($line)!==')')throw new \RuntimeException('Duplicate or malformed Sent UID trailer.');
            $this->continuation=false;$this->fetchComplete=true;return;
        }
        if(preg_match('/^\* +([1-9][0-9]{0,9}) +FETCH /iD',$line)){
            if(preg_match('/^\* +([1-9][0-9]{0,9}) +FETCH +\((?:UID +([1-9][0-9]{0,9}) +)?BODY\[\] +\{(0|[1-9][0-9]{0,9})\}$/iD',$line,$m)!==1||(float)$m[1]>4294967295||++$this->fetches>1)throw new \RuntimeException('Unexpected Sent copy FETCH.');
            $this->fetch=true;$this->fetchComplete=false;$this->fetchUid=$m[2]===''?null:$this->validUid($m[2]);$this->literalLength=(int)$m[3];
        }
        if($this->validity!==null&&preg_match_all('/\bUIDVALIDITY +([^ ]\S*)/i',$line,$generations)){
            foreach($generations[1] as $token){$value=rtrim($token,'])');if(preg_match('/^[1-9][0-9]{0,9}$/D',$value)!==1||(int)$value!==$this->validity)throw new \RuntimeException('Sent mailbox generation changed.');}
        }
        if(preg_match('/^\* +(0|[1-9][0-9]{0,9}) +(EXISTS|RECENT|EXPUNGE)$/iD',$line,$m)){if((float)$m[1]>4294967295)throw new \RuntimeException('Invalid Sent mailbox count.');return;}
        if(stripos($line,'[APPENDUID')!==false){
            if(preg_match('/^(?:\*|[A-Z0-9_.-]+) +OK +\[APPENDUID +([1-9][0-9]{0,9}) +([1-9][0-9]{0,9})\](?: .*)?$/iD',$line,$m)!==1||(float)$m[1]>4294967295||(float)$m[2]>4294967295||$this->validity===null||(int)$m[1]!==$this->validity||++$this->appendProofs>1)throw new \RuntimeException('Invalid or ambiguous APPENDUID proof.');return;
        }
        $this->grammar->line($line);
    }
    public function command(string $command):void {
        $this->check();if(++$this->commands>64||!in_array($command,['CAPABILITY','LOGIN','AUTHENTICATE','STARTTLS','ENABLE','ID','NAMESPACE','LANGUAGE','EXAMINE','STATUS','APPEND','UID FETCH','UNSELECT','LOGOUT'],true))throw new \RuntimeException('Sent copy command refused.');
        if(($command==='APPEND'&&++$this->appends>1)||($command==='UID FETCH'&&++$this->reads>1))throw new \RuntimeException('Sent copy command replay refused.');
    }
    public function literal(int $length):void {$this->check();if($length<0||$length>($this->fetch?$this->artifactBytes:32768))throw new \RuntimeException('Sent copy literal exceeds approved bytes.');if($this->fetch){if($this->continuation||$this->literalLength!==$length)throw new \RuntimeException('Unexpected Sent body literal.');$this->continuation=true;}else $this->grammar->literal($length);}
}
