<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Content;
use OCA\Mail\Sync\RefreshBudget;
/** Private read-only session budget. Every check precedes native token storage or MIME construction. */
final class ContentBudget {
    public const MAX_BYTES=26214400;
    public const MAX_WIRE=41943040;
    private RefreshBudget $grammar;
    private int $decoded=0;
    private int $decodedLimit=self::MAX_BYTES;
    public function displayText():void {if($this->decoded!==0)throw new \RuntimeException('Display budget must precede body reads.');$this->decodedLimit=1048576;}
    private int $bytes=0,$lines=0,$metadataBytes=0,$commands=0,$selections=0,$nodes=0,$mimeDepth=0,$parameterBytes=0;
    private ?string $mode=null;
    private string $section='';
    private bool $fetch=false,$complete=false,$tagged=false,$continuation=false;
    private ?int $literalLength=null,$fetchUid=null;
    private array $tokens=[],$mimeParameters=[];
    private int $depth=0,$opens=0;
    public function __construct(private int $validity,private int $uid) {
        if($validity<1||$validity>4294967295||$uid<1||$uid>4294967295)throw new \RuntimeException('Invalid content identity.');
        $this->grammar=new RefreshBudget();
    }
    public function check():void {$this->grammar->check();}
    public function decoded(int $bytes):void {$this->check();if($bytes<0||$bytes>$this->decodedLimit-$this->decoded)throw new \RuntimeException('Aggregate decoded content exceeds its bound.',$this->decodedLimit===1048576?413:0);$this->decoded+=$bytes;}
    /** Nonblocking reads keep the absolute deadline active even without a newline or complete literal. */
    public function readLine($stream):string|false {return $this->readWire($stream,true,8192);}
    public function readLiteral($stream,int $length):string|false {if($length<1||$length>8192)throw new \RuntimeException('Invalid bounded literal chunk.');return $this->readWire($stream,false,$length);}
    private function readWire($stream,bool $line,int $length):string|false {
        $this->check();if(!is_resource($stream))throw new \RuntimeException('Content stream unavailable.');
        $blocking=(bool)(stream_get_meta_data($stream)['blocked']??true);$idle=hrtime(true)+2_000_000_000;
        if(!stream_set_blocking($stream,false))throw new \RuntimeException('Content stream cannot be bounded.');
        try {while(true){
            $this->check();$chunk=$line?fgets($stream,$length+1):fread($stream,$length);$this->check();
            if($chunk!==false&&$chunk!=='')return $chunk;if(feof($stream))return false;
            if(hrtime(true)>=$idle)throw new \RuntimeException('Content read inactivity deadline exhausted.');
            $read=[$stream];$write=[];$except=[];if(@stream_select($read,$write,$except,0,100000)===false)throw new \RuntimeException('Content stream wait failed.');
        }}finally{stream_set_blocking($stream,$blocking);}
    }
    public function consume(int $bytes):void {
        $this->check();if($bytes<0||$bytes>self::MAX_WIRE-$this->bytes)throw new \RuntimeException('Content wire budget exhausted.');$this->bytes+=$bytes;
        if($this->mode==='manifest'){if($bytes>131072-$this->metadataBytes)throw new \RuntimeException('Content metadata byte limit exceeded.');$this->metadataBytes+=$bytes;}
    }
    public function expect(string $mode,string $section=''):void {
        $this->check();if($this->mode!==null)$this->assertFetch();
        if(!in_array($mode,['raw','manifest','header','part','flags'],true)||($mode==='part'&&preg_match('/^[1-9][0-9]?(?:\.[1-9][0-9]?){0,15}$/D',$section)!==1)||($mode==='header'&&$section!=='HEADER'&&preg_match('/^[1-9][0-9]?(?:\.[1-9][0-9]?){0,15}\.MIME$/D',$section)!==1)||(!in_array($mode,['part','header'],true)&&$section!==''))throw new \RuntimeException('Unsupported content section.');
        $this->mode=$mode;$this->section=$section;$this->fetch=false;$this->complete=false;$this->tagged=false;$this->continuation=false;$this->literalLength=null;$this->fetchUid=null;$this->tokens=[];$this->depth=0;$this->opens=0;
    }
    public function beginResponse():void {$this->check();if($this->fetch&&!$this->complete)throw new \RuntimeException('Incomplete content response.');$this->grammar->beginResponse();}
    public function command(string $name):void {
        $this->check();if(++$this->commands>230||!in_array($name,['CAPABILITY','LOGIN','AUTHENTICATE','STARTTLS','ENABLE','ID','NAMESPACE','LANGUAGE','EXAMINE','UID FETCH','LOGOUT'],true)||($name==='EXAMINE'&&++$this->selections>1)||($name==='UID FETCH'&&($this->mode===null||$this->fetch)))throw new \RuntimeException('Content command refused.');
    }
    public function assertFetch():void {$this->check();if(!$this->fetch||!$this->complete||!$this->tagged||$this->fetchUid!==$this->uid)throw new \RuntimeException('Complete exact content proof unavailable.');}
    private function exactUid(string $uid):int {if(preg_match('/^[1-9][0-9]{0,9}$/D',$uid)!==1||(int)$uid!==$this->uid)throw new \RuntimeException('Content UID changed.');return (int)$uid;}
    public function line(string $line):void {
        $this->check();if(strlen($line)>32768||++$this->lines>512||($this->mode==='manifest'&&$this->lines>128))throw new \RuntimeException('Content protocol line limit exceeded.');
        if($this->continuation){
            $this->continuation=false;
            if($this->mode==='manifest'){$this->scan($line);return;}
            if($this->fetchUid===null){if(preg_match('/^ +UID +([1-9][0-9]{0,9})\)$/iD',$line,$m)!==1)throw new \RuntimeException('Invalid content UID trailer.');$this->fetchUid=$this->exactUid($m[1]);}
            elseif(trim($line)!==')')throw new \RuntimeException('Unexpected content trailer.');
            $this->complete=true;return;
        }
        if(preg_match('/^\* +([1-9][0-9]{0,9}) +FETCH +/iD',$line,$m)){
            if($this->mode===null||$this->fetch||(float)$m[1]>4294967295)throw new \RuntimeException('Unexpected content FETCH.');$this->fetch=true;
            if($this->mode==='manifest'){$this->scan(substr($line,strlen($m[0])));return;}
            if($this->mode==='flags'){
                if(preg_match('/^\* +[1-9][0-9]{0,9} +FETCH +\((?:UID +([1-9][0-9]{0,9}) +)?FLAGS +\(([A-Za-z0-9\\\\_$ .-]{0,1024})\)(?: +UID +([1-9][0-9]{0,9}))?\)$/iD',$line,$f)!==1||(!empty($f[1])&&!empty($f[3])))throw new \RuntimeException('Unsupported content flags.');$this->fetchUid=$this->exactUid(($f[1]??'')!==''?$f[1]:($f[3]??''));$this->complete=true;return;
            }
            $section=preg_quote($this->section,'/');
            if(preg_match('/^\* +[1-9][0-9]{0,9} +FETCH +\((?:UID +([1-9][0-9]{0,9}) +)?BODY\['.$section.'\] +\{(0|[1-9][0-9]{0,9})\}$/iD',$line,$f)!==1)throw new \RuntimeException('Unexpected content body section.');
            $this->fetchUid=$f[1]===''?null:$this->exactUid($f[1]);$this->literalLength=(int)$f[2];return;
        }
        if(preg_match_all('/\bUIDVALIDITY +([^ ]\S*)/i',$line,$matches))foreach($matches[1] as $token){$value=rtrim($token,'])');if(preg_match('/^[1-9][0-9]{0,9}$/D',$value)!==1||(int)$value!==$this->validity)throw new \RuntimeException('Content generation changed.');}
        if(preg_match('/^\* +(0|[1-9][0-9]{0,9}) +(EXISTS|RECENT)$/iD',$line,$m)){if((float)$m[1]>4294967295)throw new \RuntimeException('Invalid content mailbox count.');return;}
        if(preg_match('/^\* +[0-9]+ +EXPUNGE/i',$line))throw new \RuntimeException('Content mailbox changed during read.');
        $this->preflightStatus($line);$this->grammar->line($line);
        if($this->fetch&&preg_match('/^[A-Z0-9_.-]+ +(OK|NO|BAD|BYE)(?: |$)/iD',$line,$m)){if(strtoupper($m[1])!=='OK'||!$this->complete)throw new \RuntimeException('Content command did not complete.');$this->tagged=true;}
    }
    public function literal(int $length):void {
        $this->check();$cap=match($this->mode){'raw'=>self::MAX_BYTES,'header'=>16384,'manifest'=>4096,'part'=>self::MAX_WIRE,default=>4096};
        if($length<0||$length>$cap||$length>self::MAX_WIRE-$this->bytes||($this->mode==='manifest'&&$length>131072-$this->metadataBytes)||!$this->fetch||$this->literalLength!==$length||$this->continuation)throw new \RuntimeException('Content literal exceeds its bound.');
        $this->literalLength=null;$this->continuation=true;
    }
    /** Bounded token preflight, without materializing literal bytes. Only one BODYSTRUCTURE and exact UID are admitted. */
    private function scan(string $line):void {
        $length=strlen($line);
        for($i=0;$i<$length;){
            $c=$line[$i];if($c===' '){$i++;continue;}
            if($c==='('){if(++$this->depth>24||++$this->opens>512)throw new \RuntimeException('Content metadata nesting limit exceeded.');$this->token('(');$i++;continue;}
            if($c===')'){if(--$this->depth<0)throw new \RuntimeException('Invalid metadata list.');$this->token(')');$i++;continue;}
            if($c==='"'){$start=$i++;$closed=false;while($i<$length){if($line[$i]==='\\'){$i+=2;continue;}if($line[$i++]==='"'){$closed=true;break;}}if(!$closed||$i-$start>4096)throw new \RuntimeException('Invalid metadata string.');$this->token('#string');continue;}
            if($c==='{'){if(preg_match('/^\{(0|[1-9][0-9]{0,9})\}$/D',substr($line,$i),$m)!==1||(int)$m[1]>4096)throw new \RuntimeException('Invalid metadata literal.');$this->literalLength=(int)$m[1];$this->token('#string');return;}
            $start=$i;while($i<$length&&!str_contains(' ()',$line[$i]))$i++;$atom=substr($line,$start,$i-$start);
            if(strlen($atom)>4096||preg_match('/[\x00-\x20\x7f"{}\\\\]/',$atom)|| (ctype_digit($atom)&&(strlen($atom)>10||(float)$atom>4294967295)))throw new \RuntimeException('Invalid metadata atom.');$this->token(strtoupper($atom));
        }
        if($this->depth===0)$this->validateManifest();
    }
    private function token(string $value):void {if(count($this->tokens)>=4096)throw new \RuntimeException('Content metadata token limit exceeded.');$this->tokens[]=$value;}
    private function validateManifest():void {
        $t=$this->tokens;$i=0;if(($t[$i++]??null)!=='(')throw new \RuntimeException('Invalid metadata response.');
        if(($t[$i]??null)==='UID'){$i++;$this->fetchUid=$this->exactUid($t[$i++]??'');}
        if(($t[$i++]??null)!=='BODYSTRUCTURE'||($t[$i++]??null)!=='(')throw new \RuntimeException('Unexpected metadata fields.');
        $depth=1;while(isset($t[$i])&&$depth>0){$v=$t[$i++];if($v==='(')$depth++;if($v===')')$depth--;}
        if($depth!==0)throw new \RuntimeException('Unclosed metadata tree.');
        if($this->fetchUid===null&&($t[$i++]??null)==='UID')$this->fetchUid=$this->exactUid($t[$i++]??'');
        if(($t[$i++]??null)!==')'||$i!==count($t)||$this->fetchUid===null)throw new \RuntimeException('Ambiguous metadata identity.');$this->complete=true;
    }
    private function preflightStatus(string $line):void {
        $depth=0;$opens=0;$tokens=0;$length=strlen($line);
        for($i=0;$i<$length;){
            if($line[$i]===' '){$i++;continue;}if(++$tokens>4096)throw new \RuntimeException('Content status token limit exceeded.');
            if($line[$i]==='('){if(++$depth>24||++$opens>512)throw new \RuntimeException('Content status depth exceeded.');$i++;continue;}
            if($line[$i]===')'){if(--$depth<0)throw new \RuntimeException('Invalid content status list.');$i++;continue;}
            $start=$i;if($line[$i]==='"'){$i++;$closed=false;while($i<$length){if($line[$i]==='\\'){$i+=2;continue;}if($line[$i++]==='"'){$closed=true;break;}}if(!$closed)throw new \RuntimeException('Invalid content status string.');}
            else while($i<$length&&!str_contains(' ()',$line[$i]))$i++;
            if($i-$start>4096)throw new \RuntimeException('Content status atom exceeds bound.');
        }
        if($depth!==0)throw new \RuntimeException('Invalid content status nesting.');
    }
    public function enterMimeNode():void {$this->check();if(++$this->nodes>100||++$this->mimeDepth>16)throw new \RuntimeException('Content MIME complexity limit exceeded.');$this->mimeParameters[]=['pairs'=>0,'bytes'=>0];}
    public function leaveMimeNode():void {$this->mimeDepth--;array_pop($this->mimeParameters);}
    public function parameters(array $parameters):void {
        $this->check();if(count($parameters)>64||count($parameters)%2!==0)throw new \RuntimeException('Content MIME parameter count exceeded.');$bytes=0;$seen=[];
        foreach($parameters as $i=>$value){if(!is_string($value)||strlen($value)>($i%2===0?128:4096))throw new \RuntimeException('Content MIME parameter exceeds its bound.');$bytes+=strlen($value);if($i%2===0){$key=strtolower($value);if(isset($seen[$key]))throw new \RuntimeException('Duplicate MIME parameter.');$seen[$key]=true;}}
        $node=array_key_last($this->mimeParameters);if($node!==null){$this->mimeParameters[$node]['pairs']+=intdiv(count($parameters),2);$this->mimeParameters[$node]['bytes']+=$bytes;if($this->mimeParameters[$node]['pairs']>32||$this->mimeParameters[$node]['bytes']>16384)throw new \RuntimeException('Content part parameters exceed bound.');}
        if($bytes>16384||$bytes>131072-$this->parameterBytes)throw new \RuntimeException('Content MIME parameter bytes exceeded.');$this->parameterBytes+=$bytes;
    }
}
