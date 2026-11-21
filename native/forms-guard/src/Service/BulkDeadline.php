<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** One request budget includes authority, fence waits, projection, hashing and effects. */
final class BulkDeadline {
 private static ?float $start=null;
 private static bool $circleStage=false;
 public static function run(callable $work):mixed {
  if(self::$start!==null){return $work();}
  self::$start=microtime(true);try{return $work();}finally{self::$start=null;self::$circleStage=false;}
 }
 /** Transfer work includes indivisible native Files hooks; all other actions keep 18s. */
 public static function limit():int {return OriginContext::active()&&(OriginContext::current()['verified']['tool']??null)==='forms_transfer'?(self::$circleStage?60:25):18;}
 /** Only an indivisible TYPE7 staging request may retain its fence for up to 60s. */
 public static function admitCircleStaging():void {
  $caller=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]??[];
  if(($caller['class']??null)!==BulkTransferShares::class||($caller['function']??null)!=='stage'||self::$start===null||self::$circleStage||!OriginContext::active()||(OriginContext::current()['verified']['tool']??null)!=='forms_transfer')throw new \OCA\Forms\Mcp\BeforeEffect('Circle staging budget is unavailable');
  self::check();self::$circleStage=true;
 }
 public static function started():float {return self::$start??microtime(true);}
 public static function check():void {
  if(self::$start!==null&&microtime(true)-self::$start>self::limit()){throw new \OCA\Forms\Mcp\BeforeEffect('Bulk request deadline exhausted; committed evidence retained');}
 }
}
