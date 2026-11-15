<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Private native SQL recorder: no globals are swapped and every non-read entry is closed. */
final class FinalReadConnection extends \OC\DB\ConnectionAdapter {
 private bool $closed=false;
 public function __construct(private readonly \OC\DB\ConnectionAdapter $native,private readonly FinalReadSet $set,private readonly FinalShareBudget $budget,private readonly \Closure $frame) {
  if($native::class!==\OC\DB\ConnectionAdapter::class)throw new \RuntimeException('Final read primary connection is unqualified');
 }
 private function check():void {($this->frame)();if($this->closed)throw new \RuntimeException('Final read connection is closed');}
 public function assertPrimary(\OCP\IDBConnection$db):void {$this->check();if($db!==$this->native)throw new \RuntimeException('Final read primary binding differs');}
 public function finish():void {$this->closed=true;}
 public function getQueryBuilder():\OCP\DB\QueryBuilder\IQueryBuilder {
  $this->check();$query=$this->native->getQueryBuilder();
  if($query::class!==\OC\DB\QueryBuilder\QueryBuilder::class)throw new \RuntimeException('Final read native query is unqualified');
  return FinalReadQuery::capture($query,$this);
 }
 public function executeQuery(string $sql,array $params=[],$types=[]):\OCP\DB\IResult {
  $this->check();$this->set->validate($sql,$params,$types);
  // The original native query and native parameter types are used verbatim.
  $result=$this->native->executeQuery($sql,$params,$types);
  $rows=$this->budget->rows($result->fetchAssociative(...),$result->closeCursor(...),1000);
  $this->set->add($sql,$params,$types,$rows);$this->check();
  return new FinalReadResult($rows,$this->check(...));
 }
public function getTypedQueryBuilder(): \OCP\DB\QueryBuilder\ITypedQueryBuilder
{
    throw new \LogicException('Final read connection method is closed');
}
public function prepare($sql, $limit = null, $offset = null): \OCP\DB\IPreparedStatement
{
    throw new \LogicException('Final read connection method is closed');
}
public function executeUpdate(string $sql, array $params = [], array $types = []): int
{
    throw new \LogicException('Final read connection method is closed');
}
public function executeStatement($sql, array $params = [], array $types = []): int
{
    throw new \LogicException('Final read connection method is closed');
}
public function lastInsertId(string $table): int
{
    throw new \LogicException('Final read connection method is closed');
}
public function insertIfNotExist(string $table, array $input, ?array $compare = null)
{
    throw new \LogicException('Final read connection method is closed');
}
public function insertIgnoreConflict(string $table, array $values): int
{
    throw new \LogicException('Final read connection method is closed');
}
public function setValues($table, array $keys, array $values, array $updatePreconditionValues = []): int
{
    throw new \LogicException('Final read connection method is closed');
}
public function lockTable($tableName): void
{
    throw new \LogicException('Final read connection method is closed');
}
public function unlockTable(): void
{
    throw new \LogicException('Final read connection method is closed');
}
public function beginTransaction(): void
{
    throw new \LogicException('Final read connection method is closed');
}
public function inTransaction(): bool
{
    $this->check();
    return $this->native->inTransaction();
}
public function commit(): void
{
    throw new \LogicException('Final read connection method is closed');
}
public function rollBack(): void
{
    throw new \LogicException('Final read connection method is closed');
}
public function getError(): string
{
    throw new \LogicException('Final read connection method is closed');
}
public function errorCode()
{
    throw new \LogicException('Final read connection method is closed');
}
public function errorInfo()
{
    throw new \LogicException('Final read connection method is closed');
}
public function connect(): bool
{
    throw new \LogicException('Final read connection method is closed');
}
public function close(): void
{
    throw new \LogicException('Final read connection method is closed');
}
public function quote($input, $type = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)
{
    $this->check();
    return $this->native->quote($input, $type);
}
public function getDatabasePlatform(): \Doctrine\DBAL\Platforms\AbstractPlatform
{
    $this->check();
    return $this->native->getDatabasePlatform();
}
public function dropTable(string $table): void
{
    throw new \LogicException('Final read connection method is closed');
}
public function truncateTable(string $table, bool $cascade): void
{
    throw new \LogicException('Final read connection method is closed');
}
public function tableExists(string $table): bool
{
    throw new \LogicException('Final read connection method is closed');
}
public function escapeLikeParameter(string $param): string
{
    $this->check();
    return $this->native->escapeLikeParameter($param);
}
public function supports4ByteText(): bool
{
    $this->check();
    return $this->native->supports4ByteText();
}
public function createSchema(): \Doctrine\DBAL\Schema\Schema
{
    throw new \LogicException('Final read connection method is closed');
}
public function migrateToSchema(\Doctrine\DBAL\Schema\Schema $toSchema): void
{
    throw new \LogicException('Final read connection method is closed');
}
public function getInner(): \OC\DB\Connection
{
    throw new \LogicException('Final read connection method is closed');
}
public function getDatabaseProvider(bool $strict = false): string
{
    $this->check();
    return $this->native->getDatabaseProvider($strict);
}
public function getServerVersion(): string
{
    $this->check();
    return $this->native->getServerVersion();
}
public function logDatabaseException(\Exception $exception)
{
    throw new \LogicException('Final read connection method is closed');
}
public function getShardDefinition(string $name): ?\OC\DB\QueryBuilder\Sharded\ShardDefinition
{
    throw new \LogicException('Final read connection method is closed');
}
public function getCrossShardMoveHelper(): \OC\DB\QueryBuilder\Sharded\CrossShardMoveHelper
{
    throw new \LogicException('Final read connection method is closed');
}
}
