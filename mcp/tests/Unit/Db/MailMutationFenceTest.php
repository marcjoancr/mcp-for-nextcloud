<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Mcp\Operations\NativeOperationFence;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use PHPUnit\Framework\TestCase;
final class MailMutationFenceTest extends TestCase {
    public function testRevocationStopsCacheButPreservesAlreadyObservedRemoteProof():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $request=['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true,'profile_revision'=>'p','cache_revision'=>'c'];
        $store->reserve(['scope_key'=>'scope','operation_id'=>'op','tool_name'=>'mail_set_message_flag','effect_digest'=>'effect','target_key'=>'claim','actor_uid'=>'alice','state'=>'running','resource_refs'=>['account_id'=>4],'created_at'=>1000,'updated_at'=>1000]);
        $lease=['scope'=>'scope','id'=>'op','token'=>str_repeat('a',64)];$store->checkpointNative('scope','op',$lease['token'],'',['phase'=>'prepared','descriptor'=>$request]);
        $allowed=true;$f=new NativeOperationFence($store,$lease,$request,static function()use(&$allowed):bool{return $allowed;});
        self::assertTrue($f->beforeWire($request));self::assertFalse($f->beforeWire($request));$allowed=false;
        $proof=['outcome'=>'verified','message_id'=>17,'mailbox_id'=>5,'generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true];
        self::assertTrue($f->recordRemote($proof));self::assertFalse($f->beforeCache($request));
        self::assertSame('remote_verified',$store->get('scope','op')['resource_refs']['mutation']['phase']);
        self::assertFalse($f->recordCache(['state'=>'updated','message_id'=>17]));
    }
    public function testWrongIdentityOrUnknownEvidenceCannotBecomeAReceipt():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $f=new NativeOperationFence($store,['scope'=>'scope','id'=>'op','token'=>'token'],['message_id'=>17],static fn():bool=>true);
        self::assertFalse($f->recordRemote(['outcome'=>'verified','password'=>'SECRET']));
        self::assertFalse($f->beforeWire(['message_id'=>18]));self::assertNull($store->get('scope','op'));
    }
}
