<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailRefreshCacheCallbackTest extends TestCase {
    public function testReadCallbackHasNoWriteAuthorityAndLostOwnerCannotDelete():void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Cache/OwnedSyncCache.php';
        $mapper=new class {public ?object $lease=null;public function currentSyncLease(int $id):?object{return $this->lease;}};$messages=new class {public int $deletes=0;public function deleteByUid(object $box,int ...$uids):void{$this->deletes++;}};
        $cache=new class($mapper,$messages) {use \OCA\Mail\Cache\OwnedSyncCache;public function __construct(private object $mailboxMapper,private object $dbMessageMapper){}public function callback(object $box):void{$this->deleteOwnedSyncMessages($box,[1]);}};$box=new class {public function getId():int{return 7;}};
        $cache->callback($box);self::assertSame(0,$messages->deletes);$mapper->lease=new class {public function assertOwned():void {}};$cache->callback($box);self::assertSame(1,$messages->deletes);$mapper->lease=new class {public function assertOwned():void {throw new \RuntimeException('Lost session');}};try{$cache->callback($box);self::fail('Stale owner deleted');}catch(\RuntimeException){}self::assertSame(1,$messages->deletes);
    }
}
