<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Comments;
use OCP\Server;
use OCA\Forms\Mcp\Service\BulkStore;
/** Creation-time evidence only. Existing comments never borrow a current account's identity. */
final class HistoricalAuthor {
 public static function ready():bool {return Server::get(\OCP\IDBConnection::class)->executeQuery('SELECT version FROM *PREFIX*migrations WHERE app = ? AND version = ?',['forms','050402Date20261114010000'])->fetchOne()!==false;}
 public static function record(\OCP\Comments\IComment $comment,string $uid):void {
  if(!self::ready()){return;}
  $proof=['type'=>$comment->getActorType(),'id'=>$uid,'identity_status'=>'verified_historical','principal'=>CommentsRuntime::principal($uid)];
  Server::get(\OCP\IDBConnection::class)->executeStatement('INSERT INTO *PREFIX*forms_mcp_comment_auth (comment_id,form_id,payload) VALUES (?,?,?)',[(int)$comment->getId(),(int)$comment->getObjectId(),BulkStore::encode($proof)]);
 }
 public static function capture(array $row):array {
  if(self::ready()){$p=Server::get(\OCP\IDBConnection::class)->executeQuery('SELECT payload FROM *PREFIX*forms_mcp_comment_auth WHERE comment_id = ? AND form_id = ?',[(int)$row['id'],(int)$row['object_id']])->fetchOne();if($p!==false){return BulkStore::decode($p);}}
  return ['type'=>$row['actor_type'],'id'=>$row['actor_id'],'identity_status'=>'unverified_historical'];
 }
}
