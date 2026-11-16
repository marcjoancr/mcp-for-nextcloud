<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Exact one-step native runner with a closed owned migration factory. No occ/global upgrades. */
final class GroupInstallMigration extends \OC\DB\MigrationService {
 public const VERSION='050402Date20261115020000';
 public function __construct(private readonly \OC\DB\Connection$primary,\OCP\Migration\IOutput$output,\Psr\Log\LoggerInterface$logger){parent::__construct('forms',$primary,$output,$logger);}
 public function createInstance(string$version):\OCP\Migration\IMigrationStep{
  if($version!==self::VERSION)throw new \RuntimeException('Installer migration selection refused');
  return new \OCA\Forms\Migration\Version050402Date20261115020000();
 }
 /** Called only inside the installer-owned PG lease/transaction after full source/DB preimages. */
 public function executeExact(array$expectedVersions):bool{
  if($this->primary::class!==\OC\DB\Connection::class||!$this->primary->inTransaction()||$this->primary->getDatabaseProvider(true)!=='postgres'||(new \ReflectionProperty($this->primary,'shards'))->getValue($this->primary)!==[])throw new \RuntimeException('Installer migration connection unqualified');
  $prefix=$this->primary->getPrefix();if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$prefix)!==1)throw new \RuntimeException('Installer table prefix unqualified');
  // Never call getMigratedVersions before proving its createMigrationTable fast path cannot mutate Core.
  $schema=$this->primary->createSchema();if(!$schema->hasTable($prefix.'migrations'))throw new \RuntimeException('Installer migration table unavailable');$table=$schema->getTable($prefix.'migrations');
  if(array_keys($table->getColumns())!==['app','version']||$table->getPrimaryKey()?->getColumns()!==['app','version'])throw new \RuntimeException('Installer migration table unqualified');
  foreach($table->getColumns()as$column)if(!$column->getType() instanceof \Doctrine\DBAL\Types\StringType||$column->getLength()!==255)throw new \RuntimeException('Installer migration table unqualified');
  $r=$this->primary->executeQuery('SELECT configvalue FROM *PREFIX*appconfig WHERE appid = ? AND configkey = ?',['core','vendor']);try{$vendor=$r->fetchOne();if($r->fetchOne()!==false)throw new \RuntimeException('Installer vendor state duplicated');}finally{$r->free();}
  if($vendor==='owncloud')throw new \RuntimeException('Installer vendor profile unqualified');
  $known=$this->getMigratedVersions();sort($known,SORT_STRING);sort($expectedVersions,SORT_STRING);if($known!==$expectedVersions)throw new \RuntimeException('Installer migration preimage changed');
  $available=$this->getAvailableVersions();$pending=array_values(array_diff($available,$known));
  if($pending===[]){$this->assertOwnedSchema($schema,$prefix);return false;}
  if($pending!==[self::VERSION])throw new \RuntimeException('Installer unexpected pending migration');
  foreach(['forms_mcp_group_history','forms_mcp_group_epoch']as$name)if($schema->hasTable($prefix.$name))throw new \RuntimeException('Installer partial schema requires recovery');
  // Native getDiff normalizes old STRING>4000 columns. Refuse rather than changing unrelated tables.
  foreach($schema->getTables()as$t)foreach($t->getColumns()as$c)if($c->getType() instanceof \Doctrine\DBAL\Types\StringType&&$c->getLength()>4000)throw new \RuntimeException('Installer unrelated schema normalization refused');
  $step=$this->createInstance(self::VERSION);$output=new GroupInstallOutput();
  $wrapper=$step->changeSchema($output,fn():\OCP\DB\ISchemaWrapper=>new \OC\DB\SchemaWrapper($this->primary,clone$schema),['tablePrefix'=>$prefix]);
  if(!$wrapper instanceof \OC\DB\SchemaWrapper)throw new \RuntimeException('Installer migration schema unavailable');$target=$wrapper->getWrappedSchema();
  $this->ensureUniqueNamesConstraints($target,true);$this->ensureNamingConstraints($schema,$target,strlen($prefix));
  $diff=$this->primary->createSchemaManager()->createComparator()->compareSchemas($schema,$target);$created=array_map(static fn($t):string=>$t->getName(),$diff->getCreatedTables());sort($created,SORT_STRING);$wanted=[$prefix.'forms_mcp_group_epoch',$prefix.'forms_mcp_group_history'];sort($wanted,SORT_STRING);
  if($created!==$wanted||$diff->getAlteredTables()!==[]||$diff->getDroppedTables()!==[]||$diff->getCreatedSchemas()!==[]||$diff->getDroppedSchemas()!==[]||$diff->getCreatedSequences()!==[]||$diff->getAlteredSequences()!==[]||$diff->getDroppedSequences()!==[])throw new \RuntimeException('Installer schema delta refused');
  $this->assertOwnedSchema($target,$prefix);
  // Native implementation retains its own schema algorithm and sets Migrator.noEmit itself.
  parent::executeStep(self::VERSION,false);$this->assertOwnedSchema($this->primary->createSchema(),$prefix);
  $after=$this->getMigratedVersions();$wantedVersions=[...$known,self::VERSION];sort($after,SORT_STRING);sort($wantedVersions,SORT_STRING);if($after!==$wantedVersions)throw new \RuntimeException('Installer migration result uncertain');return true;
 }
 private function assertOwnedSchema(\Doctrine\DBAL\Schema\Schema$schema,string$prefix):void{
  foreach(['forms_mcp_group_epoch'=>['id'=>['integer',null],'epoch'=>['string',64]],'forms_mcp_group_history'=>['gid_key'=>['string',64],'gid'=>['string',255],'epoch'=>['string',64],'generation'=>['string',64],'state'=>['string',8]]]as$name=>$columns){
   if(!$schema->hasTable($prefix.$name))throw new \RuntimeException('Installer owned schema missing');$table=$schema->getTable($prefix.$name);if(array_keys($table->getColumns())!==array_keys($columns)||$table->getPrimaryKey()?->getColumns()!==[$name==='forms_mcp_group_epoch'?'id':'gid_key']||count($table->getIndexes())!==1||$table->getForeignKeys()!==[])throw new \RuntimeException('Installer owned schema differs');
   foreach($columns as$key=>[$type,$length]){$c=$table->getColumn($key);if($c->getType()->getName()!==$type||($length!==null&&$c->getLength()!==$length)||!$c->getNotnull()||$c->getAutoincrement()||$c->getDefault()!==null)throw new \RuntimeException('Installer owned column differs');}
  }
 }
}
