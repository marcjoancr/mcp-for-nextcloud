<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Sync;
/** Limits and closed response grammar run before tokenization, including unsolicited responses. */
final class RefreshBudget {
    public const MAX_MESSAGES=200;
    private int $bytes=0;
    private int $fetches=0;
    private int $lines=0;
    private int $until;
    private ?string $responseType=null;
    private bool $literalContinuation=false;
    public function __construct() {$this->until=hrtime(true)+17_000_000_000;}
    public function check():void {if(hrtime(true)>=$this->until)throw new \RuntimeException('Refresh deadline exhausted.');}
    public function consume(int $bytes):void {$this->check();if($bytes<0||$bytes>8388608-$this->bytes)throw new \RuntimeException('Refresh byte budget exhausted.');$this->bytes+=$bytes;}
    /** One Horde read returns one logical response, including any bounded literal continuations. */
    public function beginResponse():void {$this->check();$this->responseType=null;$this->literalContinuation=false;}
    public function line(string $line):void {
        $this->check();if(strlen($line)>32768||++$this->lines>2048)throw new \RuntimeException('Refresh protocol budget exhausted.');
        if($this->literalContinuation)return;
        $body='';$type='';
        if(preg_match('/^\* +(0|[1-9][0-9]{0,9}) +([A-Z][A-Z0-9-]*)(?: +(.*))?$/iD',$line,$m)===1){
            $type=strtoupper($m[2]);$body=$m[3]??'';
            if(!in_array($type,['FETCH','EXISTS','RECENT','EXPUNGE'],true)||(float)$m[1]>4294967295||($type==='FETCH'&&(int)$m[1]<1))throw new \RuntimeException('Unsupported numeric refresh response.');
            if($type==='FETCH'){if(++$this->fetches>self::MAX_MESSAGES)throw new \RuntimeException('Refresh fetch response budget exhausted.');}
            elseif((int)$m[1]>self::MAX_MESSAGES)throw new \RuntimeException('Refresh count exceeds its bound.');
        }elseif(preg_match('/^\* +([A-Z][A-Z0-9-]*)(?: +(.*))?$/iD',$line,$m)===1){
            $type=strtoupper($m[1]);$body=$m[2]??'';
            if(!in_array($type,['OK','NO','BAD','BYE','PREAUTH','CAPABILITY','FLAGS','SEARCH','STATUS','ENABLED','NAMESPACE','ID','LANGUAGE'],true))throw new \RuntimeException('Unsupported refresh response.');
        }elseif(preg_match('/^[A-Z0-9_.-]+ +(OK|NO|BAD|BYE)(?: +(.*))?$/iD',$line,$m)===1){
            $type=strtoupper($m[1]);$body=$m[2]??'';
        }elseif(preg_match('/^\+(?: .*)?$/D',$line)===1){$type='CONTINUATION';}
        else throw new \RuntimeException('Noncanonical refresh response.');
        $this->responseType=$type;
        if($type==='SEARCH'){
            $tail=trim($body,' ');$ids=$tail===''?[]:preg_split('/ +/',$tail);
            if(count($ids)>self::MAX_MESSAGES)throw new \RuntimeException('Refresh message budget exhausted.');
            foreach($ids as$id)if(preg_match('/^[1-9][0-9]{0,9}$/D',$id)!==1||(float)$id>4294967295)throw new \RuntimeException('Unbounded refresh UID response.');
        }
        if(in_array($type,['OK','NO','BAD','BYE','PREAUTH'],true)&&$body!==''){
            // Horde also recognizes quoted/literal status-code atoms. They are deliberately not admitted.
            if(in_array($body[0],['"','{','~','('],true))throw new \RuntimeException('Noncanonical refresh status.');
            if($body[0]==='['){
                if(preg_match('/^\[([A-Z][A-Z0-9-]*)(?= |\])/D',$body,$m)!==1||!in_array($m[1],['ALERT','CONTACTADMIN','WEBALERT','BADCHARSET','CAPABILITY','PARSE','READ-ONLY','PERMANENTFLAGS','UIDNEXT','UIDVALIDITY','UNSEEN','UIDNOTSTICKY','HIGHESTMODSEQ','NOMODSEQ','UNAVAILABLE','AUTHENTICATIONFAILED','AUTHORIZATIONFAILED','EXPIRED','PRIVACYREQUIRED','CANNOT','NONEXISTENT','NOPERM','INUSE','SERVERBUG','CLIENTBUG','LIMIT','OVERQUOTA'],true))throw new \RuntimeException('Unsupported refresh status code.');
                // COPYUID/APPENDUID/MODIFIED ranges and REFERRAL endpoint changes never reach Horde.
            }
        }
    }
    public function literal(int $length):void {
        $this->check();if($length<0||$length>65536)throw new \RuntimeException('Refresh literal budget exhausted.');
        if($this->responseType!==null&&!in_array($this->responseType,['FETCH','STATUS','NAMESPACE','ID'],true))throw new \RuntimeException('Unexpected refresh literal.');$this->literalContinuation=true;
    }
    public function command(string $command):void {$this->check();if(!in_array($command,['CAPABILITY','LOGIN','AUTHENTICATE','STARTTLS','ENABLE','ID','NAMESPACE','LANGUAGE','EXAMINE','STATUS','UID SEARCH','UID FETCH','LOGOUT'],true))throw new \RuntimeException('Refresh command refused.');}
}
