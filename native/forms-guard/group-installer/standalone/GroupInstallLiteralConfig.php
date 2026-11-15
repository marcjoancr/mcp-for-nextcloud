<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Closed var_export grammar. Never require/eval a config, call a function, or resolve a constant. */
final class GroupInstallLiteralConfig {
 private array $tokens;private int $at=0;private int $count=0;
 public static function read(string$bytes):array {
  if(strlen($bytes)>65536)throw new \RuntimeException('Literal config bound');$p=new self();$p->tokens=[];
  foreach(token_get_all($bytes,TOKEN_PARSE)as$t){if(is_array($t)&&in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;$p->tokens[]=$t;}
  $p->id(T_OPEN_TAG);$p->id(T_VARIABLE,'$CONFIG');$p->char('=');$result=$p->value(0);$p->char(';');if($p->at!==count($p->tokens)||!is_array($result))throw new \RuntimeException('Literal config grammar');return$result;
 }
 private function id(int$id,?string$text=null):void{$t=$this->tokens[$this->at++]??null;if(!is_array($t)||$t[0]!==$id||($text!==null&&$t[1]!==$text))throw new \RuntimeException('Literal config grammar');}
 private function char(string$char):void{if(($this->tokens[$this->at++]??null)!==$char)throw new \RuntimeException('Literal config grammar');}
 private function value(int$depth):mixed {
  if($depth>16||++$this->count>4096)throw new \RuntimeException('Literal config bound');$t=$this->tokens[$this->at]??null;
  if(is_array($t)&&$t[0]===T_ARRAY){$this->at++;$this->char('(');$out=[];
   while(($this->tokens[$this->at]??null)!==')'){$key=$this->value($depth+1);if(!is_int($key)&&!is_string($key))throw new \RuntimeException('Literal config key');$this->id(T_DOUBLE_ARROW);if(array_key_exists($key,$out))throw new \RuntimeException('Literal config duplicate');$out[$key]=$this->value($depth+1);if(($this->tokens[$this->at]??null)===',')$this->at++;elseif(($this->tokens[$this->at]??null)!==')')throw new \RuntimeException('Literal config grammar');}
   $this->char(')');return$out;
  }
  if(!is_array($t))throw new \RuntimeException('Literal config scalar');$this->at++;
  if($t[0]===T_CONSTANT_ENCAPSED_STRING&&str_starts_with($t[1],"'")){
   $s=substr($t[1],1,-1);$out='';for($i=0;$i<strlen($s);$i++){if($s[$i]==='\\'&&isset($s[$i+1])&&in_array($s[$i+1],["'",'\\'],true))$i++;$out.=$s[$i];}return$out;
  }
  if($t[0]===T_LNUMBER&&preg_match('/^(0|[1-9][0-9]*)$/D',$t[1])===1){$n=filter_var($t[1],FILTER_VALIDATE_INT);if($n===false)throw new \RuntimeException('Literal config integer');return$n;}
  if($t[0]===T_STRING)return match($t[1]){'true'=>true,'false'=>false,'NULL','null'=>null,default=>throw new \RuntimeException('Literal config constant')};
  throw new \RuntimeException('Literal config scalar');
 }
 public static function maintenance(string$before,bool$value):string{$data=self::read($before);$data['maintenance']=$value;return"<?php\n".'$CONFIG = '.var_export($data,true).";\n";}
}
