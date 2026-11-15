<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\Files\{Folder,IRootFolder};
use OCP\Server;
/** Actual user-Files destinations only; private recovery appdata never enters this policy. */
final class DestinationFiles {
 public static function authorize(Folder $folder,bool $create):void {
  OriginContext::assertFileEffect();
  // Root::get constructs a fresh Node/FileInfo rather than retaining permissions
  // from the Folder object obtained before native lock/event waits.
  $fresh=Server::get(IRootFolder::class)->get($folder->getPath());
  if(!$fresh instanceof Folder||(int)$fresh->getId()!==(int)$folder->getId()||$fresh->getOwner()?->getUID()!==$folder->getOwner()?->getUID()) {throw new \RuntimeException('Forms destination identity changed');}
  StorageAdmission::node($fresh);
  Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assertDestination(OriginContext::current()['origin'],$fresh,$create);
  BulkDeadline::check();
 }
 /** Admission before allocation, without requiring the ancestor above an allowed folder to be writable. */
 public static function admit(Folder $root,string $path):void {
  $folder=$root;self::authorize($folder,false);
  foreach(explode('/',trim($path,'/'))as$part){if($part===''||$part==='.'||$part==='..'){throw new \RuntimeException('Invalid destination path');}if(!$folder->nodeExists($part)){self::authorize($folder,true);return;}$next=$folder->get($part);if(!$next instanceof Folder){throw new \RuntimeException('Forms destination parent is not a folder');}$folder=$next;self::authorize($folder,false);}
  self::authorize($folder,true);
 }
 public static function callback():\Closure {return self::authorize(...);}
}
