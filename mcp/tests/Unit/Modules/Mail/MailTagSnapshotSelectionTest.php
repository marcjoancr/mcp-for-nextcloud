<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
/** Generation must describe the connection which actually fetched the native snapshot. */
final class MailTagSnapshotSelectionTest extends TestCase {
    public function testSnapshotCannotOutliveItsConnectionSelectionOrGeneration():void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/IMAP/TagSnapshot.php';
        $c=new class {use \OCA\Mail\IMAP\TagSnapshot;
            public object $_connection;public string $_selected='INBOX';public bool $_isAuthenticated=true;public int $_mode=1;public string $epoch='42';
            public function __construct(){$this->_connection=new \stdClass();}
            public function openMailbox($name,$mode=null):void{$this->_selected=$name;}
            public function _mailboxOb():object{return new class($this->epoch){public function __construct(private string $epoch){}public function getStatus($key):string{return $this->epoch;}};}
            private function tagSnapshotState():array{return [$this->_connection,$this->_selected,$this->epoch,$this->_mode,$this->_isAuthenticated];}
            public function check():void{$this->assertTagSnapshot();}
        };
        // Pure selection proof has no Horde dependency; begin takes the current already-selected mailbox.
        $c->beginTagSnapshot('INBOX');$c->check();self::assertSame('42',$c->finishTagSnapshot());
        foreach(['connection','folder','epoch','auth']as$change){$c->_selected='INBOX';$c->epoch='42';$c->_isAuthenticated=true;$c->beginTagSnapshot('INBOX');if($change==='connection')$c->_connection=new \stdClass();if($change==='folder')$c->_selected='Other';if($change==='epoch')$c->epoch='43';if($change==='auth')$c->_isAuthenticated=false;$refused=false;try{$c->check();}catch(\RuntimeException){$refused=true;}self::assertTrue($refused);$c->cancelTagSnapshot();}
    }
}
