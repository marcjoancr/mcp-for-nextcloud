<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Mailboxes\MailboxTree;
use PHPUnit\Framework\TestCase;
final class MailMailboxMutationsTest extends TestCase {
    public function testRenameBindsDescendantsAndRejectsRoleFoldersAndCollision():void {
        $row=static fn(int $id,string $name):array=>['id'=>$id,'name'=>$name,'delimiter'=>'.','selectable'=>true,'shared'=>false,'special'=>[],'subscribed'=>true,'generation'=>'42','cache_revision'=>'cache'];
        $m=['namespace'=>null,'roles'=>[99],'folders'=>[$row(1,'Work'),$row(2,'Work.Child'),$row(3,'Elsewhere')]];
        $r=MailboxTree::prepare($m,['action'=>'rename_mailbox','mailbox_id'=>1,'name'=>'Reviewed']);self::assertSame('Reviewed',$r['new_name']);self::assertCount(2,$r['tree']);
        foreach([['name'=>'Elsewhere'],['name'=>'Bad*'],['name'=>'INBOX'],['name'=>'Work.Other']] as $bad){try{MailboxTree::prepare($m,['action'=>'rename_mailbox','mailbox_id'=>1]+$bad);self::fail('Unsafe folder rename');}catch(\InvalidArgumentException){}}
        $m['roles']=[2];$this->expectException(\InvalidArgumentException::class);MailboxTree::prepare($m,['action'=>'rename_mailbox','mailbox_id'=>1,'name'=>'Reviewed']);
    }
    public function testCreateUsesExistingOwnedParentAndSubscriptionRequiresBoolean():void {
        $m=['namespace'=>null,'roles'=>[],'folders'=>[['id'=>1,'name'=>'Work','delimiter'=>'/','selectable'=>true,'shared'=>false,'special'=>[],'subscribed'=>false,'generation'=>'42','cache_revision'=>'cache']]];
        $r=MailboxTree::prepare($m,['action'=>'create_mailbox','parent_mailbox_id'=>1,'name'=>'Child']);self::assertSame('Work/Child',$r['new_name']);
        $this->expectException(\InvalidArgumentException::class);MailboxTree::prepare($m,['action'=>'subscribe_mailbox','mailbox_id'=>1,'subscribed'=>'yes']);
    }
    public function testUnknownFolderEpochCannotAuthorizeAReplacementByItsName():void {
        $row=['id'=>1,'name'=>'Reviewed','delimiter'=>'/','selectable'=>true,'shared'=>false,'special'=>[],'subscribed'=>false,'generation'=>null,'cache_revision'=>'null-or-conflicting-tokens'];$m=['namespace'=>null,'roles'=>[],'folders'=>[$row]];
        foreach([['action'=>'rename_mailbox','mailbox_id'=>1,'name'=>'Renamed'],['action'=>'subscribe_mailbox','mailbox_id'=>1,'subscribed'=>true],['action'=>'create_mailbox','parent_mailbox_id'=>1,'name'=>'Child']]as$args){$refused=false;try{MailboxTree::prepare($m,$args);}catch(\InvalidArgumentException){$refused=true;}self::assertTrue($refused,'Unknown preview epoch must not adopt a recreated folder by name.');}
        $m['folders'][0]['generation']='42';$m['folders'][]=$row+[];$m['folders'][1]['id']=2;$m['folders'][1]['name']='Reviewed/Unsynchronized';$this->expectException(\InvalidArgumentException::class);MailboxTree::prepare($m,['action'=>'rename_mailbox','mailbox_id'=>1,'name'=>'Renamed']);
    }

}
