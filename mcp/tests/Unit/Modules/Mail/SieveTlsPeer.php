<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
/** Synthetic loopback peer: stores scripts in memory and has no delivery/forwarding backend. */
final class SieveTlsPeer {
    private int $pid;private string $dir;public int $port;public string $ca;private bool $startTls=false;
    public function __construct(string $scenario) {
        $this->startTls=$scenario==='starttls';$startTls=$this->startTls;
        $this->dir=dirname(__DIR__,4).'/build/check-tmp/sieve-'.bin2hex(random_bytes(6));mkdir($this->dir,0700,true);
        $key=openssl_pkey_new(['private_key_bits'=>2048]);$csr=openssl_csr_new(['commonName'=>'localhost'],$key,['digest_alg'=>'sha256']);$cert=openssl_csr_sign($csr,null,$key,1,['digest_alg'=>'sha256']);openssl_x509_export($cert,$pem);openssl_pkey_export($key,$private);$this->ca=$this->dir.'/ca.pem';file_put_contents($this->ca,$pem);file_put_contents($this->dir.'/server.pem',$pem.$private);chmod($this->dir.'/server.pem',0600);
        $ctx=stream_context_create(['ssl'=>['local_cert'=>$this->dir.'/server.pem','verify_peer'=>false]]);$server=stream_socket_server('tcp://127.0.0.1:0',$err,$msg,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$ctx);$this->port=(int)substr(strrchr(stream_socket_get_name($server,false),':'),1);
        $pid=pcntl_fork();if($pid===-1)throw new \RuntimeException('fork failed');if($pid===0){$count=[];$socket=@stream_socket_accept($server,3);fclose($server);if(!$socket)exit(2);stream_set_timeout($socket,3);if(!$startTls&&!@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_SERVER))exit(3);
            $put=static function(string $s)use($socket):void{$i=0;while($i<strlen($s)){$n=@fwrite($socket,substr($s,$i,8192));if(!$n)return;$i+=$n;}};
            $caps=($startTls?'"STARTTLS"'."\r\n":'').'"IMPLEMENTATION" "synthetic"'."\r\n".'"SASL" "PLAIN"'."\r\n".'"SIEVE" "fileinto imap4flags vacation date relational variables copy"'."\r\nOK\r\n";
            if($scenario==='login')$caps=str_replace('"PLAIN"','"LOGIN"',$caps);if($scenario==='cram')$caps=str_replace('"PLAIN"','"CRAM-MD5"',$caps);
            if($scenario==='greeting_line')$put(str_repeat('x',8192)."\r\n");elseif($scenario==='greeting_caps')$put(str_repeat('"A" "B"'."\r\n",65)."OK\r\n");elseif($scenario==='partial')$put("OK\n");elseif($scenario==='slow')for($i=0;$i<20;$i++){if(!@fwrite($socket,'x'))break;usleep(950000);}else $put($caps);
            $scripts=['original'=>'# harmless'];$active='original';
            while(($line=@fgets($socket,524288))!==false){$cmd=strtok($line,' ');$cmd=rtrim($cmd);$count[$cmd]=($count[$cmd]??0)+1;
                if($cmd==='STARTTLS'){$put("OK\r\n");if(!@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_SERVER))break;$put($caps);}
                elseif($cmd==='CAPABILITY')$put($caps);
                elseif($cmd==='AUTHENTICATE'){if($scenario==='auth_literal'){$put("{8193}\r\n");break;}if($scenario==='auth_private'){$put('NO "AUTH_CANARY_PRIVATE"'."\r\n");break;}
                    if(in_array($scenario,['login','cram','plain_challenge'],true)){
                        $read=static fn()=>base64_decode(trim(fgets($socket,8193),"\"\r\n"),true);
                        if($scenario==='login'){$put('"'.base64_encode('Username:').'"'."\r\n");$user=$read();$put('"'.base64_encode('Password:').'"'."\r\n");$ok=$user==='synthetic'&&$read()==='AUTH_CANARY_PRIVATE';}
                        elseif($scenario==='cram'){$challenge='synthetic-challenge';$put('"'.base64_encode($challenge).'"'."\r\n");$ok=$read()==='synthetic '.hash_hmac('md5',$challenge,'AUTH_CANARY_PRIVATE');}
                        else{$put("\"\"\r\n");$ok=$read()==="\0synthetic\0AUTH_CANARY_PRIVATE";}
                        if(!$ok){$put("NO\r\n");break;}$count['AUTH_VERIFIED']=1;
                    }$put("OK\r\n");}
                elseif($cmd==='LISTSCRIPTS'){
                    if($scenario==='referral'){$put('BYE (REFERRAL "sieve://127.0.0.2:9999") "PRIVATE"'."\r\n");break;}
                    elseif($scenario==='names')$put('"'.str_repeat('x',256).'" ACTIVE'."\r\nOK\r\n");
                    elseif($scenario==='many_names'){for($i=0;$i<101;$i++)$put('"n'.$i.'"'."\r\n");$put("OK\r\n");}
                    elseif($scenario==='multiple_active')$put('"a" ACTIVE'."\r\n".'"b" ACTIVE'."\r\nOK\r\n");
                    else{foreach($scripts as$n=>$s)$put('"'.$n.'"'.($n===$active?' ACTIVE':'')."\r\n");$put("OK\r\n");}
                }elseif($cmd==='GETSCRIPT'){
                    preg_match('/"([^"]+)"/',$line,$m);$s=$scripts[$m[1]]??'';
                    if($scenario==='literal_large')$put("{262145}\r\n");elseif($scenario==='decimal')$put("{999999999999999999999999}\r\n");elseif($scenario==='literal_zero_progress'){$put("{10}\r\nabc");break;}
                    elseif($scenario==='second_literal')$put("{1}\r\nx\r\n{1}\r\ny\r\nOK\r\n");
                    elseif($scenario==='trailing_no')$put('{'.strlen($s)."}\r\n".$s."\r\nNO \"SCRIPT_CANARY_PRIVATE\"\r\n");
                    elseif($scenario==='status_literal')$put('{'.strlen($s)."}\r\n".$s."\r\nOK {8193}\r\n");
                    else $put('{'.strlen($s)."}\r\n".$s."\r\nOK\r\n");
                }elseif($cmd==='PUTSCRIPT'){
                    preg_match('/"([^"]+)" \{([0-9]+)\+\}/',$line,$m);$n=(int)$m[2];$s='';while(strlen($s)<$n){$part=fread($socket,$n-strlen($s));if($part==='')break;$s.=$part;}fread($socket,2);$scripts[$m[1]]=$s;if($scenario==='lost_put')break;$put("OK\r\n");
                }elseif($cmd==='SETACTIVE'){preg_match('/"([^"]+)"/',$line,$m);$active=$m[1];if($scenario==='lost_active')break;$put("OK\r\n");}else break;
            }
            fclose($socket);file_put_contents($this->dir.'/counts.json',json_encode($count));exit(0);
        }$this->pid=$pid;fclose($server);
    }
    public function params():array {return ['host'=>'localhost','port'=>$this->port,'secure'=>$this->startTls?'tls':'ssl','user'=>'synthetic','password'=>'AUTH_CANARY_PRIVATE','context'=>['ssl'=>['cafile'=>$this->ca]]];}
    public function finish():array {pcntl_waitpid($this->pid,$status);$data=is_file($this->dir.'/counts.json')?json_decode(file_get_contents($this->dir.'/counts.json'),true):[];foreach(glob($this->dir.'/*')as$f)unlink($f);rmdir($this->dir);return $data;}
}
