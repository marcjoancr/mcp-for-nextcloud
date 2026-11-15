<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Quiescent local data proof only; no Core/bootstrap/model/config execution. Paths never leave the process. */
final class GroupInstallTree {
 public static function snapshot(string$root):array{
  if($root!==realpath($root)||is_link($root)||!is_dir($root))throw new \RuntimeException('Installer data root unqualified');$until=microtime(true)+30;$paths=[$root];$rows=[];$bytes=0;
  foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST)as$file){$paths[]=$file->getPathname();if(count($paths)>20000||microtime(true)>$until)throw new \RuntimeException('Installer data bound');}
  sort($paths,SORT_STRING);$h=hash_init('sha256');foreach($paths as$path){if(microtime(true)>$until||is_link($path))throw new \RuntimeException('Installer data profile changed');clearstatcache(true,$path);$s=lstat($path);if(!is_array($s))throw new \RuntimeException('Installer data stat unavailable');$type=$s['mode']&0170000;if(!in_array($type,[0040000,0100000],true)||($type===0100000&&$s['nlink']!==1))throw new \RuntimeException('Installer data type unqualified');$hash=null;
   if($type===0100000){$bytes+=$s['size'];if($bytes>1073741824)throw new \RuntimeException('Installer data byte bound');$hash=hash_file('sha256',$path);if(microtime(true)>$until)throw new \RuntimeException('Installer data deadline');if($hash===false)throw new \RuntimeException('Installer data unavailable');}
   hash_update($h,json_encode([substr($path,strlen($root)),$type,$s['mode']&07777,$s['uid'],$s['gid'],$type===0100000?$s['size']:0,$hash],JSON_THROW_ON_ERROR)."\n");
  }return['data_digest'=>hash_final($h),'data_count'=>count($paths),'data_bytes'=>$bytes];
 }
}
