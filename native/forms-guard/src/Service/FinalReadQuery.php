<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Exact fresh native builder state; alternate connections and every statement writer are closed. */
final class FinalReadQuery extends \OC\DB\QueryBuilder\QueryBuilder {
 private FinalReadConnection $recorder;
 private function __construct() {}
 public static function capture(\OC\DB\QueryBuilder\QueryBuilder$native,FinalReadConnection$recorder):self {
  if($native::class!==\OC\DB\QueryBuilder\QueryBuilder::class)throw new \RuntimeException('Final read native query is unqualified');
  $copy=new self();$copy->recorder=$recorder;
  // Pinned TypedQueryBuilder has no state. This exact native builder was freshly constructed by Core.
  foreach((new \ReflectionClass(\OC\DB\QueryBuilder\QueryBuilder::class))->getProperties()as$p){
   if($p->isStatic())throw new \RuntimeException('Final read native query state is unqualified');
   if($p->isInitialized($native))$p->setValue($copy,$p->getValue($native));
  }
  // Keep the native connection private for expression construction (DBAL requires getInner).
  // All query execution still routes explicitly through the recorder below.
  return$copy;
 }
 public function getConnection(){return$this->recorder;}
 public function executeQuery(?\OCP\IDBConnection$connection=null):\OCP\DB\IResult {
  if($connection!==null)throw new \LogicException('Final read alternate connection is closed');
  return parent::executeQuery($this->recorder);
 }
 public function executeStatement(?\OCP\IDBConnection$connection=null):int {throw new \LogicException('Final read query writes are closed');}
}
