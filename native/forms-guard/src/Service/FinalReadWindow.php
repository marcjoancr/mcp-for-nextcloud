<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** One transaction-local primary snapshot, after all materialization and metadata admission. */
final class FinalReadWindow {
 private FinalReadSet $set;
 private FinalReadConnection $reader;
 private ?array $statement=null;
 private bool $used=false;
 private string $provider;
 private string $prefix;
 public function __construct(private readonly \OC\DB\ConnectionAdapter$db,FinalShareBudget$budget,private readonly \Closure$frame) {
  if($db::class!==\OC\DB\ConnectionAdapter::class||$db!==\OCP\Server::get(\OCP\IDBConnection::class)||$db->getInner()::class!==\OC\DB\Connection::class||!$db->inTransaction())throw new \RuntimeException('Final read primary transaction is unavailable');
  $this->provider=$db->getDatabaseProvider(true);
  if(!in_array($this->provider,['postgres','mariadb'],true))throw new \RuntimeException('Final read database profile is unqualified');
  $version=$db->getServerVersion();
  if(($this->provider==='postgres'&&preg_match('/^16\./D',$version)!==1)||($this->provider==='mariadb'&&preg_match('/^10\.11\.19(?:-|$)/D',$version)!==1))throw new \RuntimeException('Final read database lineage is unqualified');
  $this->prefix=$db->getInner()->getPrefix();
  if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$this->prefix)!==1)throw new \RuntimeException('Final read table prefix is unqualified');
  $this->set=new FinalReadSet($frame,$this->prefix);$this->reader=new FinalReadConnection($db,$this->set,$budget,$frame);
 }
 public function reader():FinalReadConnection {if($this->statement!==null||$this->used)throw new \RuntimeException('Final read window is closed');return$this->reader;}
 private function metadata(string$sql,array$params=[]):array {
  ($this->frame)();$r=$this->db->executeQuery($sql,$params);try{$rows=[];while(($row=$r->fetchAssociative())!==false){if(count($rows)>=100)throw new \RuntimeException('Final read metadata is unqualified');$rows[]=$row;}return$rows;}finally{$r->closeCursor();}
 }
 /** May wait/refuse; MUST finish before the final MCP assertPair/impact boundary. */
 public function admit():void {
  if($this->statement!==null||$this->used)throw new \RuntimeException('Final read window is closed');
  $fence=\OCP\Server::get(FormsRevisionFence::class);
  if(!$fence->active()||(new \ReflectionProperty($fence,'db'))->getValue($fence)!==$this->db)throw new \RuntimeException('Final read owned transaction is unavailable');
  // The exact bundled FormsRevisionFence owns BEGIN and sets READ COMMITTED for this transaction.
  // Maria @@tx_isolation is only a session default and is deliberately NOT used as current-transaction proof.
  $this->reader->finish();$this->set->seal();
  $plan=$this->set->statement($this->provider==='postgres'?'pgsql':'mysql');$tables=$this->set->tables();
  if($this->provider==='postgres'){
   $state=$this->metadata("SELECT current_setting('transaction_isolation') AS isolation,setting AS timeout FROM pg_settings WHERE name = 'statement_timeout'");
   if(count($state)!==1||$state[0]['isolation']!=='read committed'||!ctype_digit((string)$state[0]['timeout']))throw new \RuntimeException('Final read database profile is unqualified');
   $prior=(int)$state[0]['timeout'];$timeout=$prior===0?1000:min(1000,$prior);
   $this->db->executeStatement("SET LOCAL statement_timeout = '".$timeout."ms'");
   $names=array_map(fn(string$t):string=>'"'.$this->prefix.$t.'"',$tables);
   // ACCESS SHARE conflicts only with ACCESS EXCLUSIVE, never ordinary Core DML.
   $this->db->executeStatement('LOCK TABLE '.implode(',',$names).' IN ACCESS SHARE MODE NOWAIT');
   foreach($tables as$table){
    $rows=$this->metadata('SELECT c.relkind,c.relrowsecurity,c.relhassubclass,a.amname FROM pg_class c JOIN pg_am a ON a.oid=c.relam WHERE c.oid=to_regclass(?)',[$this->prefix.$table]);
    if(count($rows)!==1||$rows[0]['relkind']!=='r'||!in_array($rows[0]['relrowsecurity'],[false,0,'0'],true)||!in_array($rows[0]['relhassubclass'],[false,0,'0'],true)||$rows[0]['amname']!=='heap')throw new \RuntimeException('Final read table profile is unqualified');
   }
  }else{
   $state=$this->metadata('SELECT @@max_statement_time AS timeout');
   if(count($state)!==1||preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',(string)$state[0]['timeout'])!==1)throw new \RuntimeException('Final read database profile is unqualified');
   $prior=(float)$state[0]['timeout'];$timeout=($prior>0&&$prior<1)?(string)$state[0]['timeout']:'1';
   $prefix='SET STATEMENT lock_wait_timeout=0,max_statement_time='.$timeout.' FOR ';
   // In a transaction, reading a table retains shared metadata admission to COMMIT; no row locks.
   $admission=[];foreach($tables as$t)$admission[]='SELECT 1 AS admitted FROM `'.$this->prefix.$t.'` WHERE 1=0';
   $this->metadata($prefix.implode(' UNION ALL ',$admission));
   foreach($tables as$t){$rows=$this->metadata('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$this->prefix.$t]);if(count($rows)!==1||$rows[0]['TABLE_TYPE']!=='BASE TABLE'||$rows[0]['ENGINE']!=='InnoDB')throw new \RuntimeException('Final read table profile is unqualified');}
   $plan['sql']=$prefix.$plan['sql'];
  }
  ($this->frame)();$this->statement=$plan;
 }
 /** Exactly one non-locking SELECT, then comparison/DTO decisions only; no retry. */
 public function verify():void {
  if($this->used||$this->statement===null)throw new \RuntimeException('Final read window is closed');
  $this->used=true;($this->frame)();$start=hrtime(true);$p=$this->statement;
  $r=$this->db->executeQuery($p['sql'],$p['params'],$p['types']);
  self::consume($this->set,$r,$start,$this->frame,static fn():int=>hrtime(true));
 }
 private static function consume(FinalReadSet$set,\OCP\DB\IResult$r,int$start,\Closure$frame,\Closure$clock):void {
  try{$rows=(function()use($r):\Generator{while(($row=$r->fetchAssociative())!==false)yield$row;})();$set->verify($rows);}
  finally{$r->closeCursor();}
  // Server timeouts are cooperative, particularly on MariaDB. Completion after this bound always refuses.
  if($clock()-$start>1000000000)throw new \RuntimeException('Final read latency exceeded');
  $frame();
 }
 public function close():void {$this->used=true;$this->reader->finish();$this->statement=null;}
}
