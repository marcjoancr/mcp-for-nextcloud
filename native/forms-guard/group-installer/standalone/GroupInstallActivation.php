<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Installer-only epoch mutation; active GroupLease is mandatory, never borrowed or replaced. */
final class GroupInstallActivation {
 public static function rotate(\OCP\IDBConnection$db,?string$prior,string$next,bool$retiring):void{
  [$operation]=GroupInstallState::epochAction($prior,$next);$metadata=GroupProfile::assertCurrent();
  if(($retiring&&($prior===null||$metadata!==$prior))||(!$retiring&&$metadata!==$next))throw new \RuntimeException('Installer activation metadata differs');
  GroupLease::run($db,static function()use($db,$prior,$next,$operation):void{
   $rows=self::rows($db);$expected=$prior===null?[]:[['id'=>1,'epoch'=>$prior]];if($rows!==$expected)throw new \RuntimeException('Installer epoch preimage changed');
   $count=$operation==='insert'?$db->executeStatement('INSERT INTO *PREFIX*forms_mcp_group_epoch (id,epoch) VALUES (?,?)',[1,$next]):$db->executeStatement('UPDATE *PREFIX*forms_mcp_group_epoch SET epoch = ? WHERE id = ? AND epoch = ?',[$next,1,$prior]);
   if($count!==1||self::rows($db)!==[['id'=>1,'epoch'=>$next]])throw new \RuntimeException('Installer epoch update uncertain');
  });
  // Commit acknowledgement is insufficient on its own; caller also proves child exit and independent snapshot.
  if(self::rows($db)!==[['id'=>1,'epoch'=>$next]])throw new \RuntimeException('Installer epoch commit uncertain');
 }
 private static function rows(\OCP\IDBConnection$db):array{
  $r=$db->executeQuery('SELECT id,epoch FROM *PREFIX*forms_mcp_group_epoch');
  try{$rows=[];while(($row=$r->fetchAssociative())!==false){if(count($rows)>=1||!in_array($row['id']??null,[1,'1'],true)||!is_string($row['epoch']??null)||preg_match('/^[a-f0-9]{64}$/D',$row['epoch'])!==1)throw new \RuntimeException('Installer epoch state unqualified');$rows[]=['id'=>1,'epoch'=>$row['epoch']];}return$rows;}finally{$r->closeCursor();}
 }
}
