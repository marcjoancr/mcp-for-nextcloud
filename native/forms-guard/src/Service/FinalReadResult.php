<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Already bounded complete data from one native SELECT; no cursor/connection escapes. */
final class FinalReadResult implements \OCP\DB\IResult {
 private int $position=0;
 private bool $closed=false;
 public function __construct(private array $rows,private readonly \Closure $frame) {}
 private function check():void {($this->frame)();if($this->closed)throw new \RuntimeException('Final native result is closed');}
 public function closeCursor():bool{$this->closed=true;$this->rows=[];return true;}
 public function fetch(int $fetchMode=\PDO::FETCH_ASSOC){return match($fetchMode){\PDO::FETCH_ASSOC=>$this->fetchAssociative(),\PDO::FETCH_NUM=>$this->fetchNumeric(),default=>throw new \RuntimeException('Final native fetch mode is unqualified')};}
 public function fetchAssociative():array|false{$this->check();return$this->rows[$this->position++]??false;}
 public function fetchNumeric():array|false{$r=$this->fetchAssociative();return$r===false?false:array_values($r);}
 public function fetchOne(){$r=$this->fetchNumeric();return$r===false?false:$r[0];}
 public function fetchAll(int $fetchMode=\PDO::FETCH_ASSOC):array{$out=[];while(($r=$this->fetch($fetchMode))!==false)$out[]=$r;return$out;}
 public function fetchAllAssociative():array{return$this->fetchAll();}
 public function fetchAllNumeric():array{return$this->fetchAll(\PDO::FETCH_NUM);}
 public function fetchFirstColumn():array{return array_map(static fn(array$r)=>$r[0],$this->fetchAllNumeric());}
 public function fetchColumn(){return$this->fetchOne();}
 public function rowCount():int{$this->check();return count($this->rows);}
 public function iterateNumeric():\Traversable{while(($r=$this->fetchNumeric())!==false)yield$r;}
 public function iterateAssociative():\Traversable{while(($r=$this->fetchAssociative())!==false)yield$r;}
}
