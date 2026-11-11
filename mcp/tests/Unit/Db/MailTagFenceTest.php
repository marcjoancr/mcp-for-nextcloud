<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Mcp\Operations\NativeOperationFence;
use OCA\Mcp\Modules\Mail\Mutations\TagMutationRequest;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Tests\Unit\Modules\Mail\MailTagMutationRequestTest;
use PHPUnit\Framework\TestCase;
final class MailTagFenceTest extends TestCase {
    public function testTagDelegationAndWireAreSingleUseAndRequireExactOpaqueCacheEvidence():void {
        $r=MailTagMutationRequestTest::request();$sql=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$s=new OperationsMapper($sql->connection());$s->reserve(['scope_key'=>'s','operation_id'=>'o','tool_name'=>'mail_set_message_tag','effect_digest'=>'effect','target_key'=>'claim','actor_uid'=>'alice','state'=>'running','resource_refs'=>[],'created_at'=>1000,'updated_at'=>1000]);
        self::assertTrue($s->checkpointNative('s','o','t','',['phase'=>'prepared','descriptor'=>$r]));$allowed=true;$f=new NativeOperationFence($s,['scope'=>'s','id'=>'o','token'=>'t'],$r,static function()use(&$allowed):bool{return $allowed;},static fn():bool=>true,TagMutationRequest::remote(...),TagMutationRequest::cache(...));
        self::assertTrue($f->beforeWire($r));self::assertFalse($f->beforeWire($r));$p=['outcome'=>'verified','message_id'=>17,'mailbox_id'=>5,'generation'=>'42','uid'=>'9','tag_id'=>7,'value'=>true];self::assertFalse($f->recordRemote(array_replace($p,['tag_id'=>8])));self::assertTrue($f->recordRemote($p));
        $allowed=false;self::assertFalse($f->beforeCache($r));$allowed=true;self::assertTrue($f->beforeCache($r));$c=['state'=>'updated','message_id'=>17,'tag_id'=>7,'value'=>true];self::assertFalse($f->recordCache(array_replace($c,['value'=>false])));self::assertTrue($f->recordCache($c));self::assertTrue($s->finishNative('s','o','t','completed','succeeded',[],1001));self::assertFalse($f->beforeWire($r));self::assertNull($s->get('s','o')['target_key']);
    }
}
