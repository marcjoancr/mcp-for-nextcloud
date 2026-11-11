<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Operations;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Operations\OperationStore;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class OperationJournalTest extends TestCase {
    private array $rows = [];
    private int $now = 1000;
    private ConnectionContext $connection;
    private OperationJournal $journal;

    protected function setUp(): void {
        $this->connection = new ConnectionContext();
        $this->connection->set(12);
        $store = $this->createMock(OperationStore::class);
        $store->method('get')->willReturnCallback(fn(string $scope, string $id): ?array => $this->rows[$scope . $id] ?? null);
        $store->method('reserve')->willReturnCallback(function(array $row): bool {
            if (isset($this->rows[$row['scope_key'] . $row['operation_id']])) return false;
            foreach ($this->rows as $other) {
                if ($row['target_key'] !== null && $other['target_key'] === $row['target_key']) return false;
            }
            $this->rows[$row['scope_key'] . $row['operation_id']] = $row;
            return true;
        });
        $store->method('finish')->willReturnCallback(function(string $scope, string $id, string $state, array $refs, int $time): void {
            $key = $scope . $id;
            $this->rows[$key]['state'] = $state;
            $this->rows[$key]['resource_refs'] = $refs;
            $this->rows[$key]['updated_at'] = $time;
            if ($state !== 'uncertain') $this->rows[$key]['target_key'] = null;
        });
        $store->method('list')->willReturnCallback(fn(string $scope, int $limit, int $offset): array =>
            array_slice(array_values(array_filter($this->rows, fn(array $r): bool => $r['scope_key'] === $scope)), $offset, $limit));
        $clock = $this->createMock(ITimeFactory::class);
        $clock->method('getTime')->willReturnCallback(fn(): int => $this->now);
        $this->journal = new OperationJournal($store, new PlanState((new InMemoryConfig())->mock($this)), $this->connection, $clock, new \OCA\Mcp\Tests\Support\OperationActors());
    }

    public function testUnprovenLegacyOperationStaysOccupiedAndCannotReplay(): void {
        $args=['account_id'=>4];$plan=$this->journal->plan('mail_write_draft','alice',$args,['plan_state'=>'approved']);
        $args+=['operation_id'=>$plan['operation_id'],'plan_state'=>'approved'];$effects=0;
        $this->journal->execute('mail_write_draft','alice',$args,function()use(&$effects):array{$effects++;return ['draft'=>['id'=>77]];});
        foreach($this->rows as &$row)unset($row['resource_refs']['actor_incarnation']);unset($row);
        try{$this->journal->execute('mail_write_draft','alice',$args,function()use(&$effects):array{$effects++;return [];});self::fail('Unknown historical actor was adopted');}
        catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){self::assertSame(1,$effects);self::assertCount(1,$this->rows);}
    }

    public function testRepeatedConfirmationReturnsTheSameResourceWithoutRepeatingEffect(): void {
        $args = ['subject' => 'Test', 'account_id' => 4];
        $plan = $this->journal->plan('mail_write_draft', 'alice', $args, ['plan_state' => 'approved']);
        $confirmed = $args + ['operation_id' => $plan['operation_id'], 'plan_state' => 'approved'];
        $calls = 0;
        $effect = function() use (&$calls): array { $calls++; return ['draft' => ['id' => 77, 'body_plain' => 'private']]; };
        $first = $this->journal->execute('mail_write_draft', 'alice', $confirmed, $effect);
        $second = $this->journal->execute('mail_write_draft', 'alice', $confirmed, $effect);
        self::assertSame(1, $calls);
        self::assertSame(77, $first['draft']['id']);
        self::assertSame(77, $second['operation']['resource_refs']['draft_id']);
        self::assertTrue($second['operation']['replayed']);
        self::assertStringNotContainsString('private', json_encode($this->rows));
    }

    public function testSharedDestinationApprovalDoesNotChangeThePlannedEffectIdentity(): void {
        $args=['message_id'=>4,'attachment_id'=>'1','path'=>'/shared/report.pdf'];
        $p=$this->journal->plan('mail_save_attachment','alice',$args,['plan_state'=>'proof']);
        $result=$this->journal->execute('mail_save_attachment','alice',$args+['operation_id'=>$p['operation_id'],
            'plan_state'=>'proof','confirm'=>true,'confirm_shared'=>true],fn():array=>['file'=>['fileId'=>50]]);
        self::assertSame('succeeded',$result['operation']['state']);
    }

    public function testChangedArgumentsOrAnotherConnectionCannotReuseTheOperation(): void {
        $args = ['account_id' => 4, 'subject' => 'A'];
        $p = $this->journal->plan('mail_write_draft', 'alice', $args, ['plan_state' => 'proof']);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->journal->execute('mail_write_draft', 'alice', ['account_id' => 4, 'subject' => 'B',
            'operation_id' => $p['operation_id'], 'plan_state' => 'proof'], fn(): array => throw new \LogicException('Must not run'));
    }

    public function testFailureAfterAnEffectIsUncertainAndNeverReplayed(): void {
        $args = ['account_id' => 4, 'draft_id' => 77];
        $p = $this->journal->plan('mail_write_draft', 'alice', $args, ['plan_state' => 'proof']);
        $args += ['operation_id' => $p['operation_id'], 'plan_state' => 'proof'];
        $calls = 0;
        $effect = function() use (&$calls): array { $calls++; throw new \RuntimeException('mail secret'); };
        $first = $this->journal->execute('mail_write_draft', 'alice', $args, $effect);
        $again = $this->journal->execute('mail_write_draft', 'alice', $args, $effect);
        self::assertSame('uncertain', $first['operation']['state']);
        self::assertSame(1, $calls);
        self::assertFalse($again['operation']['safe_to_retry']);
        self::assertStringNotContainsString('mail secret', json_encode($first));
    }

    public function testNativeFailureWithoutObservedDraftCannotBeRepairedFromStagedFiles(): void {
        $args = ['account_id' => 4];
        $p = $this->journal->plan('mail_write_draft', 'alice', $args, ['plan_state' => 'proof']);
        $result = $this->journal->execute('mail_write_draft', 'alice', $args + ['operation_id' => $p['operation_id'], 'plan_state' => 'proof'], function(): array {
            $this->journal->checkpoint(['staged' => [['attachment_id' => 1, 'file_id' => 2, 'sha256' => 'hash']]]);
            $this->journal->checkpoint(['native_write_started' => true]);
            throw new \InvalidArgumentException('Native call may already have persisted the draft');
        });
        self::assertSame('uncertain', $result['operation']['state']);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->journal->repairResources('alice', $p['operation_id']);
    }

    public function testExpiredNewConfirmationCannotStartButCompletedReceiptRemainsReadable(): void {
        $args = ['account_id' => 4];
        $p = $this->journal->plan('mail_write_draft', 'alice', $args, ['plan_state' => 'proof']);
        $this->now += 901;
        $this->expectException(\InvalidArgumentException::class);
        $this->journal->execute('mail_write_draft', 'alice', $args + ['operation_id' => $p['operation_id'], 'plan_state' => 'proof'],
            fn(): array => throw new \LogicException('Expired plan'));
    }

    public function testReceiptReadsRecheckOriginalActionPermission(): void {
        $args=['account_id'=>4];
        $p=$this->journal->plan('mail_write_draft','alice',$args,['plan_state'=>'proof']);
        $this->journal->execute('mail_write_draft','alice',$args+['operation_id'=>$p['operation_id'],'plan_state'=>'proof'],fn():array=>['draft'=>['id'=>77]]);
        $allowed=true;
        $reader=new \OCA\Mcp\Modules\Mail\Operations\MailOperationReader($this->journal,static function(string $uid,string $tool) use (&$allowed): bool {return $allowed;});
        self::assertCount(1,$reader->list('alice',20,0)['operations']);
        $allowed=false;
        self::assertSame([],$reader->list('alice',20,0)['operations']);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $reader->get('alice',$p['operation_id']);
    }

    public function testStatusIsScopedToActorAndConnection(): void {
        $args = ['account_id' => 4];
        $p = $this->journal->plan('mail_write_draft', 'alice', $args, ['plan_state' => 'proof']);
        $this->journal->execute('mail_write_draft', 'alice', $args + ['operation_id' => $p['operation_id'], 'plan_state' => 'proof'],
            fn(): array => ['draft' => ['id' => 77]]);
        self::assertSame('succeeded', $this->journal->get('alice', $p['operation_id'])['state']);
        self::assertNull($this->journal->get('bob', $p['operation_id']));
        $this->connection->set(13);
        self::assertNull($this->journal->get('alice', $p['operation_id']));
    }
    public function testFormerActorPageIsHiddenWithoutTruncatingTheNextCurrentActorPage():void {
        $scope=$this->journal->scopeKey('alice');$token=$this->journal->actor('alice');
        for($i=0;$i<51;$i++)$this->rows[$scope.$i]=['scope_key'=>$scope,'actor_uid'=>'alice','operation_id'=>(string)$i,'tool_name'=>'mail_create_tag','state'=>'succeeded','resource_refs'=>['actor_incarnation'=>$i===50?$token:str_repeat('f',64),'tag_id'=>$i],'updated_at'=>1000];
        $reader=new \OCA\Mcp\Modules\Mail\Operations\MailOperationReader($this->journal,static fn()=>true);
        $result=$reader->list('alice',20,0);self::assertCount(1,$result['operations']);self::assertSame('50',$result['operations'][0]['operation_id']);self::assertNull($result['next_offset']);
    }

}
