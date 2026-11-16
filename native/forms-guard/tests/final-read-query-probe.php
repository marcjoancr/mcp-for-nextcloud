<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(!getenv('MCP_FORMS_NATIVE_BOUNDARY'))throw new RuntimeException('Requires disposable native cell');
ini_set('zend.exception_ignore_args','1');ini_set('display_errors','0');ini_set('log_errors','0');
try {
 define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
 $db=\OCP\Server::get(\OCP\IDBConnection::class);
 $set=new \OCA\Forms\Mcp\Service\FinalReadSet(static function():void{},$db->getInner()->getPrefix());
 $recorder=new \OCA\Forms\Mcp\Service\FinalReadConnection($db,$set,new \OCA\Forms\Mcp\Service\FinalShareBudget(),static function():void{});
 $query=\OCA\Forms\Mcp\Service\FinalReadQuery::capture($db->getQueryBuilder(),$recorder);
 $query->select('appid')->from('appconfig')->where($query->expr()->eq('appid',$query->createNamedParameter('forms')))->setMaxResults(1);
 $row=$query->executeQuery()->fetchAssociative();
 if(!is_array($row)||$row['appid']!=='forms'||$query->getConnection()!==$recorder)throw new RuntimeException('Native query routing differs');
 $set->seal();$set->verify([['q'=>0,'row'=>json_encode($row,JSON_THROW_ON_ERROR)]]);
 $closed=false;try{$recorder->getInner();}catch(LogicException){$closed=true;}if(!$closed)throw new RuntimeException('Native connection escaped');
 echo json_encode(['ok'=>true,'checks'=>['native_expression_built'=>true,'select_recorded_and_verified'=>true,'raw_connection_closed'=>true]]),"\n";
} catch(Throwable $error) {
 echo json_encode(['ok'=>false,'error_class'=>$error::class,'public_file'=>basename($error->getFile()),'line'=>$error->getLine()]),"\n";exit(1);
}
