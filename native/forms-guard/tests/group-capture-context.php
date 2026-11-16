<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCP {interface IDBConnection{} final class Server {public static function get(string$id):object{return$id===IDBConnection::class?new \OC\DB\ConnectionAdapter():new \OCA\Forms\Mcp\Service\FormsRevisionFence();}}}
namespace OC\DB {final class ConnectionAdapter implements \OCP\IDBConnection {public function inTransaction():bool{return true;}}}
namespace McpFormsServerExtension {final class GroupBootstrap {public static function assertActive():void{}} final class GroupIdentity {public static function existing(object$db,string$gid):string{return$gid;}} final class GroupWitnessStore {public static ?array$row=null;public static int$writes=0;public static function epoch(object$db):string{return str_repeat('e',64);}public static function read(object$db,string$gid):?array{return self::$row;}public static function write(object$db,?array$prior,array$row):void{self::$row=$row;self::$writes++;}}}
namespace OCA\Forms\Mcp\Service {final class FormsRevisionFence {public function active():bool{return true;}public function assertSession():void{}} final class OriginContext {public static array$context=[];public static function current():array{return self::$context;}public static function assertFresh():void{}}final class FreshCore {public function __construct(string$uid){}public function groups():object{return new class {public function groupExists(string$gid):bool{return true;}public function get(string$gid):object{return new \stdClass();}};}}}
namespace {
$root=$argv[1]??dirname(__DIR__);require$root.'/standalone/GroupWitnessState.php';require$root.'/src/Service/NativeGroupHistory.php';use OCA\Forms\Mcp\Service\OriginContext as Context;use OCA\Forms\Mcp\Service\NativeGroupHistory as History;use McpFormsServerExtension\GroupWitnessStore as Store;
$origin=['tool'=>'forms_transfer','uid'=>'actor','proof'=>'synthetic-verified-origin'];$input=['id'=>42,'owner_id'=>'recipient'];Context::$context=['origin'=>$origin,'verified'=>['tool'=>'forms_transfer','uid'=>'actor'],'input'=>$input];$job=['action'=>'forms_transfer','phase'=>'capture','sealed'=>false,'uid'=>'actor','origin'=>$origin,'input'=>$input+['_mcp_origin'=>$origin]];$checks=0;
$result=History::capture('synthetic-group',$job);if($result['gid']!=='synthetic-group'||Store::$writes!==1)throw new RuntimeException('Signed transport field prevented legitimate capture');$checks++;
$normalized=$job;unset($normalized['input']['_mcp_origin']);$again=History::capture('synthetic-group',$normalized);if($again!==$result||Store::$writes!==1)throw new RuntimeException('Normalized capture changed lifetime');$checks++;
$variants=[];$v=$job;$v['input']['_mcp_origin']['proof']='other';$variants[]=$v;$v=$job;$v['origin']['proof']='other';$variants[]=$v;$v=$job;unset($v['origin']);$variants[]=$v;
foreach(['action'=>'forms_delete_form','phase'=>'apply','sealed'=>true,'uid'=>'other']as$k=>$value){$v=$job;$v[$k]=$value;$variants[]=$v;}
foreach(['id'=>43,'owner_id'=>'other','unexpected'=>'value']as$k=>$value){$v=$job;$v['input'][$k]=$value;$variants[]=$v;}
foreach($variants as$v){$denied=false;try{History::capture('synthetic-group',$v);}catch(RuntimeException){$denied=true;}if(!$denied||Store::$writes!==1)throw new RuntimeException('Changed capture authority wrote witness');$checks++;}
echo json_encode(['ok'=>true,'checks'=>$checks,'unchanged_signed_transport_accepted'=>true,'changed_authority_rejected_before_witness_write'=>true]),"\n";
}
