<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{IDBConnection,Server};
/** Per-form writer identity; zero is reserved for native cross-form account/expiry cleanup. */
final class BulkEpoch {
 private array $touched=[];
 public function __construct(private IDBConnection $db,private FormsRevisionFence $fence){}
 public static function epoch(int $form):int {$v=Server::get(IDBConnection::class)->executeQuery('SELECT revision FROM *PREFIX*forms_mcp_bulk_epoch WHERE form_id = ?',[$form])->fetchOne();return $v===false?0:(int)$v;}
 public static function touch(int $form):void {
  if(!BulkStore::ready()){return;}$self=Server::get(self::class);if(!$self->fence->active()){throw new \LogicException('Bulk epoch requires its native fence');}$self->fence->assertSession();if(isset($self->touched[$form])){return;}
  $v=self::epoch($form);if($v>=9223372036854775805){throw new \RuntimeException('Bulk source epoch exhausted');}
  $exists=$self->db->executeQuery('SELECT form_id FROM *PREFIX*forms_mcp_bulk_epoch WHERE form_id = ?',[$form])->fetchOne()!==false;
  $n=$exists?$self->db->executeStatement('UPDATE *PREFIX*forms_mcp_bulk_epoch SET revision = revision + 1 WHERE form_id = ? AND revision = ?',[$form,$v]):$self->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_bulk_epoch (form_id,revision) VALUES (?,1)',[$form]);
  if($n!==1){throw new \RuntimeException('Bulk source epoch changed');}$self->touched[$form]=true;$self->fence->afterTransaction(function()use($self,$form):void{unset($self->touched[$form]);});
 }
 public static function mutate(string $kind,\OCP\AppFramework\Db\Entity $entity,callable $work):mixed {
  return FormsRevisionFence::run(function()use($kind,$entity,$work):mixed{
   $table=match($kind){'Share'=>'forms_v2_shares','UploadedFile'=>'forms_v2_uploaded_files'};$db=Server::get(IDBConnection::class);$before=$entity->getId()===null?false:$db->executeQuery('SELECT form_id FROM *PREFIX*'.$table.' WHERE id = ?',[$entity->getId()])->fetchOne();$result=$work();$after=$db->executeQuery('SELECT form_id FROM *PREFIX*'.$table.' WHERE id = ?',[$result->getId()])->fetchOne();foreach(array_unique([$before,$after])as$id){if($id!==false){self::touch((int)$id);}}return $result;
  });
 }
 public static function runtime():string {return hash_file('sha256',dirname(__DIR__,3).'/mcp-guard-installed.json');}
}
