<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Sieve;
/** Closed native adapter. No parent auto-authentication, cached sockets, referrals or protocol logging. */
final class BoundedManageSieve {
    private SieveBudget $budget;
    /** @var resource|null */ private $socket=null;
    private array $caps=[];
    public function __construct(#[\SensitiveParameter] array $params) {
        $this->budget=new SieveBudget(); // Must precede any allocation/network/authentication.
        try {
            $host=$params['host']??'';$port=$params['port']??0;$secure=$params['secure']??'';
            $ssl=$params['context']['ssl']??[];
            if(!is_string($host)||strlen($host)>253||preg_match('/^[a-zA-Z0-9.:-]+$/D',$host)!==1||!is_int($port)||$port<1||$port>65535||!in_array($secure,['ssl','tls'],true)||($ssl['verify_peer']??true)!==true||($ssl['verify_peer_name']??true)!==true||isset($params['logger']))throw new SieveFailure();
            $ssl=array_replace($ssl,['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host,'SNI_enabled'=>true]);
            $context=stream_context_create(['ssl'=>$ssl]);$target=str_contains($host,':')?'['.$host.']':$host;
            $this->socket=@stream_socket_client('tcp://'.$target.':'.$port,$errno,$error,min(2,$this->budget->remaining()),STREAM_CLIENT_CONNECT,$context);
            if(!is_resource($this->socket))throw new SieveFailure();stream_set_blocking($this->socket,false);
            if($secure==='ssl')$this->tls();
            $this->caps=$this->capabilities($this->response('capability'));
            if($secure==='tls'){
                if(!isset($this->caps['STARTTLS']))throw new SieveFailure();$this->command('STARTTLS','status');$this->tls();
                $this->caps=$this->capabilities($this->response('capability'));
            }
            $this->caps=$this->capabilities($this->command('CAPABILITY','capability'));
            $this->getExtensions();$this->authenticate($params['user']??'',$params['password']??'');
        } catch(\Throwable) {$this->close();throw new SieveFailure();}
    }
    public function __destruct(){$this->close();}
    public function close():void {if(is_resource($this->socket))fclose($this->socket);$this->socket=null;}
    public function disconnect(bool $sendLogout=false):void {try{if($sendLogout&&is_resource($this->socket))$this->command('LOGOUT','status');}finally{$this->close();}}
    private function wait(bool $write):void {
        if(!is_resource($this->socket))throw new SieveFailure();$t=min(2,$this->budget->remaining());$sec=(int)$t;$micro=max(1,(int)(($t-$sec)*1e6));$r=$write?[]:[$this->socket];$w=$write?[$this->socket]:[];$x=[];
        if(@stream_select($r,$w,$x,$sec,$micro)!==1)throw new SieveFailure();$this->budget->remaining();
    }
    private function tls():void {do{$this->budget->remaining();$ok=@stream_socket_enable_crypto($this->socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);if($ok===false)throw new SieveFailure();if($ok===0)$this->wait(false);}while($ok!==true);}
    private function send(#[\SensitiveParameter] string $wire):void {
        $this->budget->outbound(strlen($wire));$offset=0;
        while($offset<strlen($wire)){$this->wait(true);$n=@fwrite($this->socket,substr($wire,$offset,8192));if($n===false||$n===0)throw new SieveFailure();$offset+=$n;}
    }
    private function line():string {
        $this->budget->line();$line='';
        do {
            $limit=min(SieveBudget::LINE-strlen($line),$this->budget->capacity());if($limit<=0)throw new SieveFailure();
            $this->wait(false);$chunk=@fgets($this->socket,$limit+1);
            if($chunk===false||$chunk===''){if(feof($this->socket))throw new SieveFailure();continue;}$this->budget->inbound(strlen($chunk));$line.=$chunk;
            if(str_ends_with($line,"\n")){if(!str_ends_with($line,"\r\n"))throw new SieveFailure();return substr($line,0,-2);}
        }while(true);
    }
    private function bytes(int $length):string {
        $value='';while(strlen($value)<$length){$this->wait(false);$n=min(8192,$length-strlen($value),$this->budget->capacity());$part=@fread($this->socket,$n);if($part===false||$part===''){if(feof($this->socket))throw new SieveFailure();continue;}$this->budget->inbound(strlen($part));$value.=$part;}return $value;
    }
    private function literal(#[\SensitiveParameter] string $line,int $limit):string {
        if(preg_match('/^\{([0-9]+)\}$/D',$line,$m)!==1)throw new SieveFailure();$n=$this->budget->literal($m[1],$limit);$value=$this->bytes($n);if($this->bytes(2)!=="\r\n")throw new SieveFailure();return $value;
    }
    /** Status text is bounded and discarded, never interpreted as an endpoint or exception. */
    private function terminal(#[\SensitiveParameter] string $line):bool {
        if(preg_match('/^(OK|NO|BYE)(?: |$)/D',$line,$m)!==1)return false;
        if(preg_match('/\{([0-9]+)\}$/D',$line,$literal)===1)$this->literal('{'.$literal[1].'}',SieveBudget::LINE);
        if($m[1]!=='OK')throw new SieveFailure();return true;
    }
    private function response(string $kind):array {
        $rows=[];$literal=false;
        while(true){$line=$this->line();if($this->terminal($line)){
            if($kind==='script'&&!$literal)throw new SieveFailure();return $rows;
        }
            if($kind==='script'){if($literal)throw new SieveFailure();$rows[]=$this->literal($line,SieveBudget::SCRIPT);$literal=true;}
            elseif($kind==='capability'){if(count($rows)>=SieveBudget::CAPABILITIES)throw new SieveFailure();$rows[]=$line;}
            elseif($kind==='list'){if(count($rows)>=SieveBudget::NAMES)throw new SieveFailure();$rows[]=$line;}
            else throw new SieveFailure();
        }
    }
    private function command(#[\SensitiveParameter] string $wire,string $kind):array {
        try{$this->budget->command();$this->send($wire."\r\n");return $this->response($kind);}catch(\Throwable){$this->close();throw new SieveFailure();}
    }
    private function quoted(#[\SensitiveParameter] string $s,int $max):string {if(strlen($s)>$max||preg_match('/[\x00-\x1f\x7f]/',$s))throw new SieveFailure();return '"'.str_replace(['\\','"'],['\\\\','\\"'],$s).'"';}
    private function unquote(#[\SensitiveParameter] string $s):string {if(preg_match('/^"((?:[^"\\\\\x00-\x1f\x7f]|\\\\["\\\\])*)"$/D',$s,$m)!==1)throw new SieveFailure();return preg_replace('/\\\\(["\\\\])/','$1',$m[1]);}
    private function capabilities(#[\SensitiveParameter] array $rows):array {
        $caps=[];foreach($rows as$row){if(preg_match('/^("[A-Za-z0-9-]{1,64}")(?: (".*"))?$/D',$row,$m)!==1)throw new SieveFailure();$name=strtoupper($this->unquote($m[1]));if(isset($caps[$name]))throw new SieveFailure();$caps[$name]=isset($m[2])?$this->unquote($m[2]):'';}
        return $caps;
    }
    private function challenge():?string {
        $line=$this->line();if($this->terminal($line))return null;
        $s=str_starts_with($line,'{')?$this->literal($line,SieveBudget::LINE):$this->unquote($line);
        if(strlen($s)>SieveBudget::LINE||($decoded=base64_decode($s,true))===false)throw new SieveFailure();return $decoded;
    }
    private function authenticate(#[\SensitiveParameter] string $user,#[\SensitiveParameter] string $password):void {
        if($user===''||strlen($user)>1024||strlen($password)>4096||str_contains($user,"\0")||str_contains($password,"\0"))throw new SieveFailure();
        $methods=explode(' ',$this->caps['SASL']??'');$this->budget->command();
        if(in_array('PLAIN',$methods,true)){
            $token=base64_encode("\0".$user."\0".$password);$this->send('AUTHENTICATE "PLAIN" "'.$token.'"'."\r\n");
            $challenge=$this->challenge();if($challenge!==null){if($challenge!=='')throw new SieveFailure();$this->send('"'.$token.'"'."\r\n");if($this->challenge()!==null)throw new SieveFailure();}
        }elseif(in_array('LOGIN',$methods,true)){
            $this->send("AUTHENTICATE \"LOGIN\"\r\n");if($this->challenge()===null)throw new SieveFailure();$this->send('"'.base64_encode($user).'"'."\r\n");if($this->challenge()===null)throw new SieveFailure();$this->send('"'.base64_encode($password).'"'."\r\n");if($this->challenge()!==null)throw new SieveFailure();
        }elseif(in_array('CRAM-MD5',$methods,true)){
            $this->send("AUTHENTICATE \"CRAM-MD5\"\r\n");$challenge=$this->challenge();if($challenge===null)throw new SieveFailure();$this->send('"'.base64_encode($user.' '.hash_hmac('md5',$challenge,$password)).'"'."\r\n");if($this->challenge()!==null)throw new SieveFailure();
        }else throw new SieveFailure();
    }
    public function getExtensions():array {$values=array_values(array_filter(explode(' ',strtolower($this->caps['SIEVE']??''))));if(count($values)>64){$this->close();throw new SieveFailure();}foreach($values as$v)if(preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$v)!==1){$this->close();throw new SieveFailure();}return $values;}
    /** Native names are private and never included in public errors/receipts. */
    public function scripts():array {
        $rows=$this->command('LISTSCRIPTS','list');$scripts=[];$active=null;
        try{foreach($rows as$row){if(preg_match('/^("(?:[^"\\\\]|\\\\["\\\\])*")( ACTIVE)?$/D',$row,$m)!==1)throw new SieveFailure();$name=$this->unquote($m[1]);if($name===''||strlen($name)>SieveBudget::NAME||isset($scripts[$name]))throw new SieveFailure();$scripts[$name]=true;if(isset($m[2])){if($active!==null)throw new SieveFailure();$active=$name;}}return ['names'=>array_keys($scripts),'active'=>$active];}catch(\Throwable){$this->close();throw new SieveFailure();}
    }
    public function getScript(#[\SensitiveParameter] string $name):string {return $this->command('GETSCRIPT '.$this->quoted($name,SieveBudget::NAME),'script')[0];}
    public function installScript(#[\SensitiveParameter] string $name,#[\SensitiveParameter] string $script):void {if(strlen($script)>SieveBudget::SCRIPT)throw new SieveFailure();$this->command('PUTSCRIPT '.$this->quoted($name,SieveBudget::NAME).' {'.strlen($script)."+}\r\n".$script,'status');}
    public function setActive(#[\SensitiveParameter] string $name):void {if($name==='')throw new SieveFailure();$this->command('SETACTIVE '.$this->quoted($name,SieveBudget::NAME),'status');}
}
