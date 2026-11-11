<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\{Server,IConfig,IDBConnection};
final class McpMailLegacyContentTest extends IntegrationTestCase {
    public function testUnknownLegacyGenerationAndProfileRemainRefusedWithoutStamping():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1'||getenv('MCP_IT_LEGACY_UNKNOWN')!=='1')self::markTestSkipped('Current retained native fixture only.');
        $db=Server::get(IDBConnection::class);$q=$db->getQueryBuilder();$r=$q->select('m.id','b.account_id')->from('mail_messages','m')->innerJoin('m','mail_mailboxes','b','m.mailbox_id=b.id')->innerJoin('b','mail_accounts','a','b.account_id=a.id')->where($q->expr()->eq('a.user_id',$q->createNamedParameter(MailActor::ALICE)))->andWhere($q->expr()->isNull('m.tag_cache_generation'))->andWhere($q->expr()->isNull('m.tag_cache_profile'))->setMaxResults(1)->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}
        self::assertIsArray($row,'Preserved historical unknown cache is required for the negative proof.');$id=(int)$row['id'];$before=MailSignatureModeActor::row('mail_messages',$id);
        $fence=new class {public function authorize(string $uid,int $account,int $box,int $message):void{}};
        try{(new \OCA\Mail\Content\ContentSource($db,Server::get(IConfig::class)))->message(MailActor::ALICE,$id,$fence);self::fail('Unknown historical source was admitted.');}catch(\RuntimeException $e){self::assertSame('Content source generation is unproven.',$e->getMessage());}
        try{Server::get('OCA\\Mail\\Db\\TagMapper')->exactAssociations()->requireAccountReady(MailActor::ALICE,(int)$row['account_id']);self::fail('Unknown account-wide tag source was admitted.');}catch(\RuntimeException $e){self::assertSame('Native tag account contains unknown inbound provenance.',$e->getMessage());}
        self::assertSame($before,MailSignatureModeActor::row('mail_messages',$id));
    }
}
