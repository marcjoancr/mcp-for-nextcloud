<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Modules\Mail\Integration\{MailNativeBoundary,MailRuntimePairs,NativeGuardManifest};
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\{App\IAppManager,DB\QueryBuilder\IQueryBuilder,IDBConnection,Server};
use PHPUnit\Framework\Attributes\Group;

#[Group('mail')]
final class McpMailBoundedMetadataTest extends IntegrationTestCase {
    public function testV10BoundedMetadataReadsUseTheQualifiedDirectPostgresPrimary():void {
        if($this->cellDatabase()!=='pgsql')self::markTestSkipped('This contract runs in the PostgreSQL fixture.');
        self::assertSame(\OCA\Mcp\Modules\Mail\Integration\NativeGuardManifest::DATA['build_version'],Server::get(IAppManager::class)->getAppVersion('mail'),'The current pinned native artifact is required.');

        $apps=Server::get(IAppManager::class);$boundary=new MailNativeBoundary($apps);
        self::assertTrue(MailRuntimePairs::matches(Server::get(\OCP\IConfig::class)->getSystemValueString('version',''),$apps->getAppVersion('mail')));
        self::assertTrue($boundary->boundedMetadataSourceVerified());
        self::assertArrayHasKey('lib/Db/BoundedMailMetadata.php',NativeGuardManifest::DATA['files']);

        $reader=Server::get('OCA\\Mail\\Db\\BoundedMailMetadata');
        $db=$this->official();self::assertSame(IDBConnection::PLATFORM_POSTGRES,$db->getDatabaseProvider());
        $q=$db->getQueryBuilder();$result=$q->select('id','user_id')->from('mail_accounts')
            ->where($q->expr()->in('user_id',$q->createNamedParameter([MailActor::ALICE,MailActor::BOB],IQueryBuilder::PARAM_STR_ARRAY)))->orderBy('id','ASC')->executeQuery();
        try{$accounts=$result->fetchAll();}finally{$result->closeCursor();}
        $aliceAccounts=[];$bobAccounts=[];foreach($accounts as$row){if($row['user_id']===MailActor::ALICE)$aliceAccounts[]=(int)$row['id'];if($row['user_id']===MailActor::BOB)$bobAccounts[]=(int)$row['id'];}
        self::assertNotEmpty($aliceAccounts,'The PostgreSQL fixture must contain Alice’s real Mail account.');
        self::assertNotEmpty($bobAccounts,'The PostgreSQL fixture must contain Bob’s real Mail account.');

        $subject=str_repeat('a',250).'😀😀';self::assertSame(258,strlen($subject));self::assertSame(252,mb_strlen($subject,'UTF-8'));
        $db->beginTransaction();$syntheticIds=[];
        $insert=static function(int $account,int $type,string $subject,bool $failed,int $updated)use($db,&$syntheticIds):int {
            $q=$db->getQueryBuilder();$q->insert('mail_local_messages')
                ->setValue('type',$q->createNamedParameter($type,IQueryBuilder::PARAM_INT))
                ->setValue('account_id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT))
                ->setValue('alias_id',$q->createNamedParameter(null,IQueryBuilder::PARAM_INT))
                ->setValue('send_at',$q->createNamedParameter(null,IQueryBuilder::PARAM_INT))
                ->setValue('subject',$q->createNamedParameter($subject))
                ->setValue('html',$q->createNamedParameter(false,IQueryBuilder::PARAM_BOOL))
                ->setValue('failed',$q->createNamedParameter($failed,IQueryBuilder::PARAM_BOOL))
                ->setValue('updated_at',$q->createNamedParameter($updated,IQueryBuilder::PARAM_INT));
            $q->executeStatement();$id=$q->getLastInsertId();$syntheticIds[]=$id;return $id;
        };
        try {
            $aliceOlder=$insert($aliceAccounts[0],0,'alice older',false,100);
            $bobOutgoing=$insert($bobAccounts[0],0,'bob private',true,200);
            $aliceNewer=$insert($aliceAccounts[0],0,$subject,true,300);
            $aliceDraft=$insert($aliceAccounts[0],1,'alice draft excluded',false,400);

            $first=$reader->outbox(MailActor::ALICE,null,1,null);
            self::assertSame([$aliceNewer],array_column($first['items'],'id'));self::assertSame($aliceNewer,$first['next_before_id']);
            self::assertSame($subject,$first['items'][0]['subject'],'SQL and PHP subject bounds must preserve a UTF-8 string even when its byte length crosses 256.');
            self::assertFalse($first['items'][0]['subject_truncated']);self::assertTrue($first['items'][0]['failed']);
            self::assertSame(300,$first['items'][0]['updated_at']);self::assertNull($first['items'][0]['send_at']);
            self::assertSame(['id','account_id','subject','subject_truncated','failed','send_at','updated_at'],array_keys($first['items'][0]));

            $second=$reader->outbox(MailActor::ALICE,null,1,$first['next_before_id']);
            self::assertSame([$aliceOlder],array_column($second['items'],'id'));self::assertNull($second['next_before_id']);
            $all=$reader->outbox(MailActor::ALICE,null,50,null);self::assertSame([$aliceNewer,$aliceOlder],array_column($all['items'],'id'));
            self::assertNotContains($bobOutgoing,array_column($all['items'],'id'));self::assertNotContains($aliceDraft,array_column($all['items'],'id'));
            $context=new \OCA\Mcp\Mcp\Tools\ToolContext(MailActor::ALICE);
            $mcpOutbox=Server::get(\OCA\Mcp\Modules\Mail\Tools\ReadOutbox::class)->execute(['account_id'=>$aliceAccounts[0],'limit'=>1],$context);
            $outboxResult=json_decode($mcpOutbox['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
            self::assertSame([$aliceNewer],array_column($outboxResult['items'],'id'),'The registered MCP tool must reach the qualified native projection.');
            self::assertSame($subject,$outboxResult['items'][0]['subject']);


            $idsFor=static function(string $uid)use($db):array {
                $q=$db->getQueryBuilder();$r=$q->select('m.id')->from('mail_messages','m')
                    ->leftJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))
                    ->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))
                    ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->orderBy('m.id','ASC')->setMaxResults(25)->executeQuery();
                try{return array_map(static fn(array $row):int=>(int)$row['id'],$r->fetchAll());}finally{$r->closeCursor();}
            };
            $aliceCacheIds=$idsFor(MailActor::ALICE);$bobCacheIds=$idsFor(MailActor::BOB);
            self::assertNotEmpty($aliceCacheIds,'The PostgreSQL fixture must have Alice cached Mail messages.');
            self::assertNotEmpty($bobCacheIds,'The PostgreSQL fixture must have Bob cached Mail messages.');
            $cached=$reader->cachedMessages(MailActor::ALICE,array_merge($bobCacheIds,$aliceCacheIds));
            self::assertSame($aliceCacheIds,array_column($cached,'id'),'A mixed-owner request must return exactly the requested Alice cache identities.');
            foreach($cached as$row)self::assertSame(['id','account_id','mailbox_id','subject','subject_truncated','sent_at','seen','flagged'],array_keys($row));
            $mcpMetadata=Server::get(\OCA\Mcp\Modules\Mail\Tools\ReadMessageMetadata::class)->execute(['message_ids'=>array_merge($bobCacheIds,$aliceCacheIds)],$context);
            $metadataResult=json_decode($mcpMetadata['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
            self::assertSame($aliceCacheIds,array_column($metadataResult['items'],'id'),'The registered MCP batch tool must omit foreign IDs identically to missing IDs.');
            foreach($metadataResult['items']as$row)self::assertSame(['id','account_id','mailbox_id','subject','subject_truncated','sent_at','seen','flagged'],array_keys($row));

        } finally {
            if($db->inTransaction())$db->rollBack();
        }
        if($syntheticIds!==[]){
            $q=$db->getQueryBuilder();$result=$q->select('id')->from('mail_local_messages')->where($q->expr()->in('id',$q->createNamedParameter($syntheticIds,IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery();
            try{self::assertSame([],$result->fetchAll(),'The PostgreSQL proof rows must be absent after rollback.');}finally{$result->closeCursor();}
        }
    }
}
