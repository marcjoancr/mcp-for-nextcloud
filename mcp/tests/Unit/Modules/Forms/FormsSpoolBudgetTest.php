<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Db\FormsSpoolMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version002800Date20261113110000;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class FormsSpoolBudgetTest extends TestCase {
    private SqliteDatabase $sql;private FormsSpoolMapper $budget;private int $now=1000;
    protected function setUp():void {
        $unused=$this->createMock(\OCP\IDBConnection::class);
        $this->sql=new SqliteDatabase($this,[new Version001100Date20261015000000($unused),new Version002800Date20261113110000()]);
        $this->sql->dbal->executeStatement('INSERT INTO mcp_policy_state (id,revision,lock_seq,auth_epoch) VALUES (1,0,0,0)');
        $db=$this->sql->connection();$clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(fn():int=>$this->now);
        $this->budget=new FormsSpoolMapper($db,$clock,new PolicyStateMapper($db));
    }
    private function id(int $n):string {return hash('sha256','spool-'.$n);}
    public function testReservationIsIdempotentButCannotChangeOwner():void {
        $id=$this->id(1);$this->budget->reserve($id,'alice');$this->budget->reserve($id,'alice');
        self::assertSame(1,(int)$this->sql->dbal->fetchOne('SELECT COUNT(*) FROM mcp_forms_spool'));
        $this->expectException(\RuntimeException::class);$this->budget->reserve($id,'bob');
    }
    public function testPerUserReservationRefusesBeforeAnAdditionalJobCanWrite():void {
        for($i=0;$i<4;$i++)$this->budget->reserve($this->id($i),'alice');
        try {$this->budget->reserve($this->id(4),'alice');self::fail('Per-user quota exceeded');}catch(\RuntimeException) {}
        self::assertSame(4,(int)$this->sql->dbal->fetchOne('SELECT COUNT(*) FROM mcp_forms_spool'));
        $this->budget->reserve($this->id(4),'bob');$this->budget->assertActive($this->id(4),'bob');
    }
    public function testSealingKeepsExactCiphertextChargeAndFreesUnusedCapacity():void {
        $id=$this->id(0);$key=hash('sha256','part');$this->budget->reserve($id,'alice');$this->budget->charge($id,$key,200);
        $this->budget->seal($id);$this->budget->seal($id);$this->budget->assertActive($id,'alice');
        self::assertSame(200,(int)$this->sql->dbal->fetchOne('SELECT reserved_bytes FROM mcp_forms_spool WHERE job_id = ?',[$id]));
        $this->budget->charge($id,$key,200);
        for($i=1;$i<=3;$i++)$this->budget->reserve($this->id($i),'alice');
        $this->expectException(\RuntimeException::class);$this->budget->charge($id,hash('sha256','new'),1);
    }
    public function testInstanceReservationIncludesEveryActor():void {
        for($i=0;$i<32;$i++)$this->budget->reserve($this->id($i),'actor-'.$i);
        $this->expectException(\RuntimeException::class);$this->budget->reserve($this->id(32),'next');
    }
    public function testCiphertextChargeIsDurableDeduplicatedAndBounded():void {
        $id=$this->id(1);$key=hash('sha256','cipher');$this->budget->reserve($id,'alice');
        $this->budget->charge($id,$key,FormsSpoolMapper::JOB_BYTES);$this->budget->charge($id,$key,FormsSpoolMapper::JOB_BYTES);
        self::assertSame(FormsSpoolMapper::JOB_BYTES,(int)$this->sql->dbal->fetchOne('SELECT used_bytes FROM mcp_forms_spool WHERE job_id = ?',[$id]));
        try {$this->budget->charge($id,hash('sha256','extra'),1);self::fail('Ciphertext overflow admitted');}catch(\RuntimeException) {}
        self::assertCount(1,$this->budget->parts($id));
    }
    public function testExpiredWritesRefuseAndCleanupRetainsQuotaUntilEveryChargedPartIsGone():void {
        $id=$this->id(1);$key=hash('sha256','cipher');$this->budget->reserve($id,'alice');$this->budget->charge($id,$key,20);
        $this->now+=FormsSpoolMapper::RETENTION_SECONDS;
        self::assertContains($id,$this->budget->expiring(10));self::assertTrue($this->budget->retire($id));
        try {$this->budget->charge($id,hash('sha256','late'),1);self::fail('Late worker admitted');}catch(\RuntimeException) {}
        try {$this->budget->finish($id);self::fail('Incomplete deletion released reservation');}catch(\RuntimeException) {}
        $this->budget->forget($id,$key);$this->budget->finish($id);$this->budget->finish($id);
        self::assertSame('expired',$this->sql->dbal->fetchOne('SELECT state FROM mcp_forms_spool WHERE job_id = ?',[$id]));
        $this->expectException(\RuntimeException::class);$this->budget->reserve($id,'alice');
    }
    public function testUnfinishedPhysicalWriteKeepsItsChargeAcrossExpiryAndProviderLockTimeout():void {
        $id=$this->id(1);$this->budget->reserve($id,'alice');$token=$this->budget->beginWrite($id);
        $this->now+=FormsSpoolMapper::RETENTION_SECONDS;
        self::assertNotContains($id,$this->budget->expiring(10));self::assertFalse($this->budget->retire($id));
        try {$this->budget->finishWrite($id,str_repeat('f',64));self::fail('Foreign callback cleared a write claim');}catch(\RuntimeException) {}
        $this->budget->finishWrite($id,$token);
        self::assertContains($id,$this->budget->expiring(10));self::assertTrue($this->budget->retire($id));
    }
    public function testUnacknowledgedChargeCannotReachFileIoAndRetryCannotChargeTwice():void {
        $id=$this->id(1);$key=hash('sha256','cipher');$this->budget->reserve($id,'alice');
        $this->sql->afterCommit=static fn()=>throw new \RuntimeException('lost database acknowledgement');$writes=0;
        try {$this->budget->charge($id,$key,25);$writes++;}catch(\RuntimeException) {}
        self::assertSame(0,$writes);$this->budget->charge($id,$key,25);
        self::assertSame(25,(int)$this->sql->dbal->fetchOne('SELECT used_bytes FROM mcp_forms_spool WHERE job_id = ?',[$id]));
    }
}
