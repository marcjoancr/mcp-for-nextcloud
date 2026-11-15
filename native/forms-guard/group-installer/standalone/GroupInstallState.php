<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Installer bookkeeping only; never supplies runtime authority or native group identity. */
final class GroupInstallState {
 private string $phase='preflight';
 private readonly array $steps;
 public function __construct(string$action){$this->steps=match($action){'install','validate'=>['checkpoint','schema','staged','epoch','verified','turnover1','maintenance','turnover2','health','active'],'rollback'=>['checkpoint','retired','entry_removed','turnover1','maintenance','turnover2','health','inactive'],default=>throw new \RuntimeException('Installer action refused')};}
 private static function token(string$value):void{if(preg_match('/^[a-f0-9]{64}$/D',$value)!==1)throw new \RuntimeException('Invalid installer identity');}
 public static function metadata(string$epoch,string$manifest):array{self::token($epoch);self::token($manifest);return['format'=>1,'active'=>true,'epoch'=>$epoch,'manifest_sha256'=>$manifest];}
 public static function epochAction(?string$prior,string$next):array{self::token($next);if($prior!==null)self::token($prior);if($prior===$next)throw new \RuntimeException('Installer epoch must change');return[$prior===null?'insert':'replace',$prior,$next];}
 public function phase():string{return$this->phase;}
 public function hold():void{$this->phase='hold';}
 public function advance(string$next):void{
  $index=$this->phase==='preflight'?-1:array_search($this->phase,$this->steps,true);
  if($this->phase==='hold'||$index===false||($this->steps[$index+1]??null)!==$next){$this->hold();throw new \RuntimeException('Installer transition refused');}$this->phase=$next;
 }
 /** Entry restoration grants no activation: old metadata/epoch must NEVER be restored. */
 public static function compatiblePriorEntry(?string$actual,?array$prior,string$entryHash,string$bundleManifest):bool{
  return$actual!==null&&$prior!==null&&array_keys($prior)===['sha256','role','manifest_sha256']&&$prior['role']==='owned_group_entry'&&$actual===$entryHash&&$prior['manifest_sha256']===$bundleManifest&&is_string($prior['sha256'])&&hash_equals($prior['sha256'],$actual)&&is_string($prior['manifest_sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$prior['manifest_sha256'])===1;
 }
 /** Complete independently collected state maps; no omitted table or broad bookkeeping exemption. */
 public static function unchanged(array$before,array$after,?string$epoch,?string$migration):void{
  if(array_keys($before)!==array_keys($after)||!array_key_exists('epoch',$before)||!isset($before['migrations'],$after['migrations']))throw new \RuntimeException('Installer protected state changed');
  if($epoch!==null)self::token($epoch);
  if($after['epoch']!==$epoch)throw new \RuntimeException('Installer epoch delta changed');
  $expected=$before['migrations'];if(!is_array($expected)||!array_is_list($expected))throw new \RuntimeException('Installer migration delta changed');
  if($migration!==null){if($migration!=='050402Date20261115020000'||in_array($migration,$expected,true))throw new \RuntimeException('Installer migration delta changed');$expected[]=$migration;}
  $actual=$after['migrations'];if(!is_array($actual)||!array_is_list($actual))throw new \RuntimeException('Installer migration delta changed');sort($expected,SORT_STRING);sort($actual,SORT_STRING);
  if($actual!==$expected)throw new \RuntimeException('Installer migration delta changed');
  unset($before['epoch'],$before['migrations'],$after['epoch'],$after['migrations']);if($before!==$after)throw new \RuntimeException('Installer protected state changed');
 }
}
