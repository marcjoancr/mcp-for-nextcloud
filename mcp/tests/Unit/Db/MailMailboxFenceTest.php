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
final class MailMailboxFenceTest extends TestCase {
    public function testFolderProofIsBoundToApprovedNameAndCannotReplayCreation():void {
        $r=['kind'=>'mailbox','account_id'=>4,'action'=>'create_mailbox','mailbox_id'=>0,'name'=>'','new_name'=>'Reviewed','delimiter'=>'/','subscribed'=>null,'tree'=>[],'profile_revision'=>'p','inventory_revision'=>'i'];
        self::assertTrue(MutationRequest::valid($r));self::assertSame('mail_create_mailbox',MutationRequest::tool($r));
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$s=new OperationsMapper($db->connection());$s->reserve(['scope_key'=>'s','operation_id'=>'o','tool_name'=>'mail_create_mailbox','effect_digest'=>'effect','target_key'=>'claim','actor_uid'=>'alice','state'=>'running','resource_refs'=>[],'created_at'=>1,'updated_at'=>1]);$l=['scope'=>'s','id'=>'o','token'=>'t'];self::assertTrue($s->checkpointNative('s','o','t','',['phase'=>'prepared','descriptor'=>$r]));$f=new NativeOperationFence($s,$l,$r,static fn():bool=>true,static fn():bool=>true,\OCA\Mcp\Modules\Mail\Mutations\MailboxMutationRequest::remote(...),\OCA\Mcp\Modules\Mail\Mutations\MailboxMutationRequest::cache(...));
        self::assertTrue($f->beforeWire($r));self::assertFalse($f->beforeWire($r));$p=['outcome'=>'created','action'=>'create_mailbox','account_id'=>4,'mailbox_id'=>0,'name'=>'Reviewed','folders'=>[['name'=>'Reviewed','generation'=>'42','subscribed'=>false]]];
        self::assertFalse($f->recordRemote(array_replace($p,['name'=>'Unapproved'])));self::assertFalse($f->recordRemote($p+['secret'=>'no']));self::assertTrue($f->recordRemote($p));self::assertTrue($f->beforeCache($r));self::assertFalse($f->recordCache(['state'=>'updated','account_id'=>4,'mailboxes'=>[['id'=>7,'name'=>'Wrong']]]));self::assertTrue($f->recordCache(['state'=>'updated','account_id'=>4,'mailboxes'=>[['id'=>7,'name'=>'Reviewed']]]));self::assertTrue($s->finishNative('s','o','t','completed','succeeded',[],2));self::assertFalse($f->beforeWire($r));
    }
    public function testFolderRemoteProofCannotAdoptAnUnapprovedEpoch():void {
        $row=['id'=>7,'name'=>'Reviewed','delimiter'=>'/','selectable'=>true,'shared'=>false,'special'=>[],'subscribed'=>false,'generation'=>null,'cache_revision'=>'tokens'];$r=['kind'=>'mailbox','account_id'=>4,'action'=>'subscribe_mailbox','mailbox_id'=>7,'name'=>'Reviewed','new_name'=>'Reviewed','delimiter'=>'/','subscribed'=>true,'tree'=>[$row],'profile_revision'=>'p','inventory_revision'=>'i'];
        $proof=['outcome'=>'subscribed','action'=>'subscribe_mailbox','account_id'=>4,'mailbox_id'=>7,'name'=>'Reviewed','folders'=>[['name'=>'Reviewed','generation'=>'999','subscribed'=>true]]];
        self::assertFalse(MutationRequest::valid($r));self::assertFalse(\OCA\Mcp\Modules\Mail\Mutations\MailboxMutationRequest::remote($r,$proof));$r['tree'][0]['generation']='42';self::assertTrue(MutationRequest::valid($r));self::assertFalse(\OCA\Mcp\Modules\Mail\Mutations\MailboxMutationRequest::remote($r,$proof));
    }

}
