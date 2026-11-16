<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
final class GroupInstallProtocol {
 public static function request(string$line,int$count):array{
  if(strlen($line)>1048576||$count!==1)throw new \RuntimeException('Controller input refused');
  $r=json_decode($line,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($r)||array_keys($r)!==['sequence','phase','profile']||$r['sequence']!==1||!is_array($r['profile'])||!in_array($r['phase'],['inspect','schema','stage','epoch','verify','retire','remove_entry','maintenance','maintenance_on','verify_inactive','snapshot'],true))throw new \RuntimeException('Controller input refused');return$r;
 }
 public static function response(string$bytes,int$exit,string$phase):void{
  if(strlen($bytes)>16384||$exit!==0)throw new \RuntimeException('Controller phase failed');$r=json_decode($bytes,true,4,JSON_THROW_ON_ERROR);
  $base=['ok'=>true,'stage'=>$phase,'error_class'=>null];if($phase==='snapshot'){if(!is_array($r)||array_keys($r)!==['ok','stage','error_class','data_digest','data_count','data_bytes']||!is_string($r['data_digest'])||preg_match('/^[a-f0-9]{64}$/D',$r['data_digest'])!==1||!is_int($r['data_count'])||$r['data_count']<1||$r['data_count']>32768||!is_int($r['data_bytes'])||$r['data_bytes']<0||$r['data_bytes']>2147483648)throw new \RuntimeException('Controller phase failed');$base+=array_intersect_key($r,array_flip(['data_digest','data_count','data_bytes']));}
  if($r!==$base)throw new \RuntimeException('Controller phase failed');
 }
}
