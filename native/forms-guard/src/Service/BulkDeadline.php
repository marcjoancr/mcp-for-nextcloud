<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** One request budget includes authority, fence waits, projection, hashing and effects. */
final class BulkDeadline {
 private static ?float $start=null;
 public static function run(callable $work):mixed {
  if(self::$start!==null){return $work();}
  self::$start=microtime(true);try{return $work();}finally{self::$start=null;}
 }
 public static function started():float {return self::$start??microtime(true);}
 public static function check():void {
  if(self::$start!==null&&microtime(true)-self::$start>18){throw new \OCA\Forms\Mcp\BeforeEffect('Bulk request deadline exhausted; committed evidence retained');}
 }
}
