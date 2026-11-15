<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection{}}
namespace McpFormsServerExtension {
 final class GroupProfile {public static string$epoch;public static function assertCurrent():string{return self::$epoch;}}
 final class GroupLease {public static int$calls=0;public static bool$failCommit=false;public static function run(\OCP\IDBConnection$db,\Closure$work):mixed{self::$calls++;$before=$db->rows;try{$work();if(self::$failCommit)throw new \RuntimeException('injected commit');return null;}catch(\Throwable$e){$db->rows=$before;throw$e;}}}
}
namespace {
 require dirname(__DIR__).'/standalone/GroupInstallState.php';require dirname(__DIR__).'/standalone/GroupInstallActivation.php';
 use McpFormsServerExtension\{GroupInstallActivation as A,GroupProfile as P,GroupLease as L};
 final class Result {private int$at=0;public function __construct(private array$rows,private object$db){}public function fetchAssociative():array|false{return$this->rows[$this->at++]??false;}public function closeCursor():void{$this->db->closed++;}}
 final class Db implements \OCP\IDBConnection {public int$closed=0;public array$statements=[];public bool$wrongCount=false;public function __construct(public array$rows){}public function executeQuery(string$sql):Result {if($sql!=='SELECT id,epoch FROM *PREFIX*forms_mcp_group_epoch')throw new \RuntimeException('unexpected read');return new Result($this->rows,$this);}public function executeStatement(string$sql,array$params):int{$this->statements[]=$sql;if(str_starts_with($sql,'INSERT'))$this->rows=[['id'=>$params[0],'epoch'=>$params[1]]];elseif($sql==='UPDATE *PREFIX*forms_mcp_group_epoch SET epoch = ? WHERE id = ? AND epoch = ?'){$this->rows=[['id'=>$params[1],'epoch'=>$params[0]]];}else throw new \RuntimeException('unexpected write');return$this->wrongCount?0:1;}}
 $checks=0;function check(bool$b):void{global$checks;$checks++;if(!$b)throw new \RuntimeException('test failed');}function deny(\Closure$f,string$m):void{$got=null;try{$f();}catch(\RuntimeException$e){$got=$e->getMessage();}check($got===$m);}
 $old=str_repeat('a',64);$new=str_repeat('b',64);P::$epoch=$new;$db=new Db([]);A::rotate($db,null,$new,false);check(L::$calls===1&&$db->rows===[['id'=>1,'epoch'=>$new]]&&$db->closed===3);
 P::$epoch=$old;$db=new Db([['id'=>1,'epoch'=>$old]]);A::rotate($db,$old,$new,true);check($db->rows===[['id'=>1,'epoch'=>$new]]&&P::$epoch===$old);
 $db=new Db([['id'=>1,'epoch'=>$old]]);P::$epoch=$new;L::$failCommit=true;deny(fn()=>A::rotate($db,$old,$new,false),'injected commit');check($db->rows===[['id'=>1,'epoch'=>$old]]);L::$failCommit=false;
 $db=new Db([['id'=>1,'epoch'=>$old]]);$db->wrongCount=true;deny(fn()=>A::rotate($db,$old,$new,false),'Installer epoch update uncertain');check($db->rows===[['id'=>1,'epoch'=>$old]]);
 $db=new Db([['id'=>1,'epoch'=>$new]]);deny(fn()=>A::rotate($db,$old,$new,false),'Installer epoch preimage changed');check($db->statements===[]);
 $db=new Db([['id'=>1,'epoch'=>$old],['id'=>2,'epoch'=>$old]]);deny(fn()=>A::rotate($db,$old,$new,false),'Installer epoch state unqualified');check($db->closed===1&&$db->statements===[]);
 P::$epoch=$old;$db=new Db([]);$calls=L::$calls;deny(fn()=>A::rotate($db,null,$new,false),'Installer activation metadata differs');check(L::$calls===$calls&&$db->statements===[]);
 echo json_encode(['ok'=>true,'checks'=>$checks,'pure_only'=>true]),"\n";
}
