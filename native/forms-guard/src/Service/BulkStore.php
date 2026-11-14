<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{Server,IDBConnection};
/** Permanent, encrypted recovery records. Never stored in the expiring results spool. */
final class BulkStore {
 public const MAX_ROWS=100000;
 public const MAX_BYTES=134217728;
 public const INSTANCE_BYTES=1073741824;
 public function __construct(private IDBConnection $db){}
 public static function ready():bool {return Server::get(IDBConnection::class)->executeQuery('SELECT version FROM *PREFIX*migrations WHERE app = ? AND version = ?',['forms','050402Date20261113090000'])->fetchOne()!==false;}
 public static function encode(array $v):string {return Server::get(\OCP\Security\ICrypto::class)->encrypt(json_encode($v,JSON_THROW_ON_ERROR));}
 public static function decode(string $v):array {return json_decode(Server::get(\OCP\Security\ICrypto::class)->decrypt($v),true,64,JSON_THROW_ON_ERROR);}
 public static function digest(array $v):string {return hash_hmac('sha256',json_encode($v,JSON_THROW_ON_ERROR),Server::get(\OCP\IConfig::class)->getSystemValueString('secret'));}
 public function load(string $id):array {
  if(!preg_match('/^[a-f0-9]{64}$/D',$id)){throw new \RuntimeException('Bulk operation unavailable');}
  $r=$this->db->executeQuery('SELECT payload FROM *PREFIX*forms_mcp_bulk WHERE id = ?',[$id])->fetchOne();if($r===false){throw new \RuntimeException('Bulk operation unavailable');}return self::decode($r);
 }
 public function byKey(string $key):?array {$id=$this->db->executeQuery('SELECT id FROM *PREFIX*forms_mcp_bulk WHERE operation_key = ?',[$key])->fetchOne();return $id===false?null:$this->load($id);}
 public function create(array $j):void {$p=self::encode($j);$total=(int)$this->db->executeQuery('SELECT COALESCE(SUM(bytes),0) FROM *PREFIX*forms_mcp_bulk')->fetchOne();if(strlen($p)>1048576||$total+strlen($p)>self::INSTANCE_BYTES){throw new \OCA\Forms\Mcp\BeforeEffect('Permanent recovery quota exhausted');}$this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_bulk (id,operation_key,actor_hash,payload,bytes) VALUES (?,?,?,?,?)',[$j['id'],$j['key'],$j['actor'],$p,strlen($p)]);}
 public function save(array $j):void {$p=self::encode($j);if(strlen($p)>1048576){throw new \OCA\Forms\Mcp\BeforeEffect('Bulk checkpoint exceeds budget');}$before=(int)$this->db->executeQuery('SELECT LENGTH(payload) FROM *PREFIX*forms_mcp_bulk WHERE id = ?',[$j['id']])->fetchOne();$delta=strlen($p)-$before;if($delta>0){$this->reserve($j,$delta);}elseif($delta<0){$this->db->executeStatement('UPDATE *PREFIX*forms_mcp_bulk SET bytes = bytes + ? WHERE id = ?',[$delta,$j['id']]);}$this->db->executeStatement('UPDATE *PREFIX*forms_mcp_bulk SET payload = ? WHERE id = ?',[$p,$j['id']]);}
 public function reserve(array &$j,int $bytes):void {
  $total=(int)$this->db->executeQuery('SELECT COALESCE(SUM(bytes),0) FROM *PREFIX*forms_mcp_bulk')->fetchOne();
  $current=(int)$this->db->executeQuery('SELECT bytes FROM *PREFIX*forms_mcp_bulk WHERE id = ?',[$j['id']])->fetchOne();
  if($bytes<0||$total+$bytes>self::INSTANCE_BYTES||$current+$bytes>self::MAX_BYTES){throw new \OCA\Forms\Mcp\BeforeEffect('Permanent recovery quota exhausted');}
  $j['bytes']=$current+$bytes;$this->db->executeStatement('UPDATE *PREFIX*forms_mcp_bulk SET bytes = bytes + ? WHERE id = ?',[$bytes,$j['id']]);
 }
 public function put(array &$j,string $kind,int $id,array $data):void {
  $p=self::encode($data);$d=self::digest($data);$this->reserve($j,strlen($p));
  $this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_bulk_rows (bulk_id,kind,source_id,payload,digest,mapped_id) VALUES (?,?,?,?,?,0)',[$j['id'],$kind,$id,$p,$d]);
  $j['chain']=hash('sha256',$j['chain'].$kind.':'.$id.':'.$d);$j['captured']++;
 }
 public function putMapping(array &$j,string $kind,int $id,array $data):void {$chain=$j['chain'];$count=$j['captured'];$this->put($j,$kind,$id,$data);$j['chain']=$chain;$j['captured']=$count;}
 public function page(string $id,string $kind,int $after):array {
  $result=$this->db->executeQuery('SELECT source_id,payload,digest,mapped_id FROM *PREFIX*forms_mcp_bulk_rows WHERE bulk_id = ? AND kind = ? AND source_id > ? ORDER BY source_id LIMIT 100',[$id,$kind,$after]);
  $out=[];$bytes=0;while($r=$result->fetch()){$v=self::decode($r['payload']);if(!hash_equals($r['digest'],self::digest($v))){throw new \RuntimeException('Recovery integrity verification failed');}$size=strlen(json_encode($v,JSON_THROW_ON_ERROR));if($size>1048576){throw new \RuntimeException('Recovery row exceeds page budget');}if($bytes+$size>1048576){break;}$bytes+=$size;$out[]=['id'=>(int)$r['source_id'],'data'=>$v,'mapped'=>(int)$r['mapped_id'],'digest'=>$r['digest']];}$result->closeCursor();return $out;
 }
 public function hasAfter(string $id,string $kind,int $cursor):bool {return $this->db->executeQuery('SELECT source_id FROM *PREFIX*forms_mcp_bulk_rows WHERE bulk_id = ? AND kind = ? AND source_id > ? ORDER BY source_id LIMIT 1',[$id,$kind,$cursor])->fetchOne()!==false;}
 public function one(string $id,string $kind,int $source):array {$r=$this->db->executeQuery('SELECT payload,digest FROM *PREFIX*forms_mcp_bulk_rows WHERE bulk_id = ? AND kind = ? AND source_id = ?',[$id,$kind,$source])->fetch();if(!$r){throw new \RuntimeException('Recovery reference missing');}$v=self::decode($r['payload']);if(!hash_equals($r['digest'],self::digest($v))){throw new \RuntimeException('Recovery integrity failed');}return $v;}
 public function map(string $id,string $kind,int $source,int $target):void {$this->db->executeStatement('UPDATE *PREFIX*forms_mcp_bulk_rows SET mapped_id = ? WHERE bulk_id = ? AND kind = ? AND source_id = ?',[$target,$id,$kind,$source]);}
 public function mapped(string $id,string $kind,int $source):int {$v=$this->db->executeQuery('SELECT mapped_id FROM *PREFIX*forms_mcp_bulk_rows WHERE bulk_id = ? AND kind = ? AND source_id = ?',[$id,$kind,$source])->fetchOne();if(!$v){throw new \RuntimeException('Recovery mapping missing');}return (int)$v;}
 public function receipt(string $id,string $key):?array {$r=$this->db->executeQuery('SELECT bulk_id,receipt FROM *PREFIX*forms_mcp_bulk_steps WHERE step_key = ?',[$key])->fetch();if(!$r){return null;}if($r['bulk_id']!==$id){throw new \RuntimeException('Bulk step key conflict');}return self::decode($r['receipt']);}
 public function record(string $id,string $key,array $dto):void {$payload=self::encode($dto);$j=$this->load($id);$this->reserve($j,strlen($payload));$this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_bulk_steps (bulk_id,step_key,receipt) VALUES (?,?,?)',[$id,$key,$payload]);}
}
