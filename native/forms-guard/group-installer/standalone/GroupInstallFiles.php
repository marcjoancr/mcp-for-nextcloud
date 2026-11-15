<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Restrictive-at-birth artifacts. Fault callback is used only by standalone pure tests. */
final class GroupInstallFiles {
 public function __construct(private readonly string$directory,private readonly ?\Closure$fault=null){$this->assertDirectory();}
 public static function directory(string$path):void{
  $mask=umask(0077);try{if(is_link($path)||file_exists($path)||!@mkdir($path,0700))throw new \RuntimeException('Installer private directory unavailable');}finally{umask($mask);}
  clearstatcache(true,$path);if((fileperms($path)&0777)!==0700)throw new \RuntimeException('Installer private directory unavailable');
 }
 private function assertDirectory():void{clearstatcache(true,$this->directory);if(is_link($this->directory)||!is_dir($this->directory)||realpath($this->directory)!==$this->directory||(fileperms($this->directory)&0022)!==0)throw new \RuntimeException('Installer artifact directory unavailable');}
 private function path(string$name):string{if(preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D',$name)!==1)throw new \RuntimeException('Installer artifact name refused');$this->assertDirectory();return$this->directory.'/'.$name;}
 private static function preimage(string$path,?string$expected):void{
  clearstatcache(true,$path);$s=@lstat($path);
  if($expected===null){if($s!==false)throw new \RuntimeException('Installer foreign artifact preserved');return;}
  if(preg_match('/^[a-f0-9]{64}$/D',$expected)!==1||$s===false||($s['mode']&0170000)!==0100000||!hash_equals($expected,hash_file('sha256',$path)))throw new \RuntimeException('Installer foreign artifact preserved');
 }
 private function syncDirectory():void{$h=@fopen($this->directory,'r');if($h===false)throw new \RuntimeException('Installer directory sync failed');try{if(!@fsync($h))throw new \RuntimeException('Installer directory sync failed');}finally{fclose($h);}}
 private function point(string$point,string$path):void{if($this->fault!==null)($this->fault)($point,$path);}
 public function atomic(string$name,string$bytes,?string$expected):void{
  $path=$this->path($name);self::preimage($path,$expected);$temp=$this->path('stage-'.bin2hex(random_bytes(16)));$mask=umask(0077);$h=false;$published=false;
  try{
   $h=@fopen($temp,'x+b');if($h===false)throw new \RuntimeException('Installer artifact creation failed');
   $s=fstat($h);if(($s['mode']&0777)!==0600)throw new \RuntimeException('Installer artifact mode changed');$this->point('opened',$temp);
   $length=strlen($bytes);for($at=0;$at<$length;){$n=fwrite($h,substr($bytes,$at,65536));if($n===false||$n===0)throw new \RuntimeException('Installer artifact write failed');$at+=$n;}$this->point('written',$temp);
   if(!fflush($h)||!fsync($h))throw new \RuntimeException('Installer artifact sync failed');$this->point('synced',$temp);
   rewind($h);$hash=hash_init('sha256');if(hash_update_stream($hash,$h)!==$length||!hash_equals(hash('sha256',$bytes),hash_final($hash)))throw new \RuntimeException('Installer artifact readback failed');
   $this->point('before_rename',$temp);self::preimage($path,$expected);
   if(!@rename($temp,$path))throw new \RuntimeException('Installer artifact publication failed');$published=true;$this->syncDirectory();
   clearstatcache(true,$path);if((fileperms($path)&0777)!==0600||!hash_equals(hash('sha256',$bytes),hash_file('sha256',$path)))throw new \RuntimeException('Installer artifact publication uncertain');
  }finally{umask($mask);if(is_resource($h))fclose($h);if(!$published&&is_file($temp)&&!is_link($temp))@unlink($temp);}
 }
 public function remove(string$name,string$expected):void{$path=$this->path($name);self::preimage($path,$expected);if(!@unlink($path))throw new \RuntimeException('Installer artifact removal failed');$this->syncDirectory();}
}
