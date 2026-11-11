<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use PHPUnit\Framework\TestCase;
final class MailErasureStoreTest extends TestCase {
    private function fixture():array {
        $root=dirname(__DIR__,5).'/native/mail-guard/src/';
        require_once $root.'Lifecycle/MailErasureStore.php';require_once $root.'Migration/Version5125Date20261111020000.php';
        $migration=new \OCA\Mail\Migration\Version5125Date20261111020000();$db=new SqliteDatabase($this,[$migration,$migration]);
        $db->dbal->executeStatement('CREATE TABLE mail_accounts (id INTEGER PRIMARY KEY, user_id TEXT)');
        $db->dbal->executeStatement('CREATE TABLE mail_mcp_deliveries (id INTEGER PRIMARY KEY, user_id TEXT, account_id INTEGER, artifact TEXT)');
        $db->dbal->executeStatement('CREATE TABLE mail_mcp_body_index (user_id TEXT, account_id INTEGER)');
        $db->dbal->executeStatement('CREATE TABLE mail_mcp_draft_copies (user_id TEXT, target_account_id INTEGER)');
        $db->dbal->insert('mail_accounts',['id'=>1,'user_id'=>'original']);
        foreach([[1,'original',1],[2,'original',2],[3,'other',1]] as [$id,$uid,$account])$db->dbal->insert('mail_mcp_deliveries',['id'=>$id,'user_id'=>$uid,'account_id'=>$account,'artifact'=>'private synthetic bytes']);
        return [$db,new \OCA\Mail\Lifecycle\MailErasureStore($db->connection())];
    }
    public function testCaptureIncludesOriginalOrphansButCannotExpandToLaterAccountsOrActivateWrongOwner():void {
        [$db,$store]=$this->fixture();$id=$store->capture('original');$db->dbal->insert('mail_accounts',['id'=>4,'user_id'=>'original']);
        self::assertSame([1,2],array_map('intval',$db->dbal->fetchFirstColumn('SELECT account_id FROM mail_mcp_erase_accounts ORDER BY account_id')));
        self::assertSame([],$store->pending());$store->confirm($id,'other');self::assertSame([],$store->pending());
        $store->confirm($id,'original');self::assertSame([['id'=>$id,'user_id'=>'original']],$store->pending());$store->preparedAbandoned($id);self::assertCount(1,$store->pending());
        $unconfirmed=$store->capture('original');$store->preparedAbandoned($unconfirmed);self::assertSame(0,(int)$db->dbal->fetchOne('SELECT COUNT(*) FROM mail_mcp_erasure WHERE id = ?',[$unconfirmed]));
    }
    public function testAccountPurgeExecutesNoReadsAndRollsBackWithAccountRemoval():void {
        [$db,$store]=$this->fixture();
        try{$store->transaction(function()use($store,$db):void {$store->purgeAccount('original',1);$db->dbal->delete('mail_accounts',['id'=>1]);throw new \RuntimeException('Synthetic rollback');});self::fail('Rollback did not throw.');}catch(\RuntimeException $e){self::assertSame('Synthetic rollback',$e->getMessage());}
        self::assertSame(3,(int)$db->dbal->fetchOne('SELECT COUNT(*) FROM mail_mcp_deliveries'));self::assertSame(1,(int)$db->dbal->fetchOne('SELECT COUNT(*) FROM mail_accounts'));
        $store->transaction(fn()=>$store->purgeAccount('original',1));self::assertSame(0,$db->queryCount,'Erasure must not read artifacts into memory.');self::assertSame([2,3],array_map('intval',$db->dbal->fetchFirstColumn('SELECT id FROM mail_mcp_deliveries ORDER BY id')));
    }
}
