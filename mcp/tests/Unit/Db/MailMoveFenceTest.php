<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Mcp\Operations\NativeOperationFence;
use OCA\Mcp\Modules\Mail\Mutations\MutationRequest;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use PHPUnit\Framework\TestCase;
final class MailMoveFenceTest extends TestCase {
    public function testTrashHookAndWireAreOneShotAndReceiptNeedsExactDestinationProof():void {
        $r=['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','profile_revision'=>'p','cache_revision'=>'c','action'=>'trash','destination_mailbox_id'=>6,'destination_mailbox'=>'Trash','destination_generation'=>'43','destination_cache_revision'=>'d','roles_revision'=>'roles','requires_trash_hook'=>true];
        self::assertTrue(MutationRequest::valid($r));self::assertSame('mail_trash_message',MutationRequest::tool($r));
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$s=new OperationsMapper($db->connection());
        $s->reserve(['scope_key'=>'s','operation_id'=>'o','tool_name'=>'mail_trash_message','effect_digest'=>'effect','target_key'=>'claim','actor_uid'=>'alice','state'=>'running','resource_refs'=>[],'created_at'=>1000,'updated_at'=>1000]);
        $l=['scope'=>'s','id'=>'o','token'=>'t'];self::assertTrue($s->checkpointNative('s','o','t','',['phase'=>'prepared','descriptor'=>$r]));
        $allowed=true;$f=new NativeOperationFence($s,$l,$r,static function()use(&$allowed):bool{return $allowed;},static fn():bool=>true);
        self::assertFalse($f->beforeWire($r));self::assertTrue($f->beforePreconditions($r));self::assertFalse($f->beforePreconditions($r));self::assertTrue($f->beforeWire($r));self::assertFalse($f->beforeWire($r));
        $p=['outcome'=>'moved','message_id'=>17,'mailbox_id'=>5,'generation'=>'42','uid'=>'9','destination_mailbox_id'=>6,'destination_generation'=>'43','destination_uid'=>'27','action'=>'trash'];
        foreach(['generation','uid','destination_generation','destination_mailbox_id','action'] as $key){$bad=$p;$bad[$key]='wrong';self::assertFalse($f->recordRemote($bad));}
        self::assertFalse($f->recordRemote($p+['secret'=>'no']));self::assertTrue($f->recordRemote($p));
        $allowed=false;self::assertFalse($f->beforeCache($r));$allowed=true;self::assertTrue($f->beforeCache($r));
        $c=['state'=>'source_removed','message_id'=>17,'destination_mailbox_id'=>6,'destination_uid'=>'27','destination_state'=>'native_sync_pending'];
        self::assertFalse($f->recordCache(array_replace($c,['destination_uid'=>'28'])));self::assertTrue($f->recordCache($c));
        self::assertTrue($s->finishNative('s','o','t','completed','succeeded',[],1001));self::assertFalse($f->beforeWire($r));
    }
}
