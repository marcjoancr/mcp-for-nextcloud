<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Operations\MailOperationCleanup;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../stubs/OCA/Mail/Exception/AttachmentNotFoundException.php';

final class MailOperationCleanupTest extends TestCase {
    public function testOnlyAbandonedUnassociatedSnapshotsAreDeletedAndFailuresRemainRecoverable(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);
        $store=new OperationsMapper($db->connection());
        foreach(['partial','partial','uncertain','repairing','running','partial'] as $i=>$state){
            $id=(string)($i+1);
            $row=['scope_key'=>'scope','operation_id'=>$id,'actor_uid'=>'alice','tool_name'=>'mail_write_draft',
                'effect_digest'=>'digest','target_key'=>null,'state'=>'running','resource_refs'=>[],
                'created_at'=>10,'updated_at'=>10];
            $store->reserve($row);
            $store->finish('scope',$id,$state,['staged'=>[['attachment_id'=>$i+1]]],$i===5?100000:10);
        }
        $native=new class {
            public array $deleted=[];
            public array $read=[];
            public bool $fail=true;
            public bool $metadataMissing=false;
            public function getAttachment(string $uid,int $id): array {
                $this->read[]=$id;
                if($id===1 && $this->metadataMissing) throw new \OCA\Mail\Exception\AttachmentNotFoundException();
                if(in_array($id,$this->deleted,true)) throw new DoesNotExistException('Gone');
                return [new class($id) {public function __construct(private int $id){}public function getLocalMessageId(): ?int{return $this->id===2?77:null;}}];
            }
            public function deleteAttachment(string $uid,int $id): void {
                $this->metadataMissing=true;
                if($this->fail)throw new \RuntimeException('Temporary storage failure');
                $this->deleted[]=$id;
            }
        };
        $mail=$this->createMock(MailServices::class);$mail->method('attachmentService')->willReturn($native);
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(100001);
        $cleanup=new MailOperationCleanup($store,$mail,$clock);
        $cleanup->run();
        self::assertSame('cleaning',$store->get('scope','1')['state']);
        self::assertNotEmpty($store->get('scope','1')['resource_refs']);
        self::assertSame('expired',$store->get('scope','2')['state']);
        $native->fail=false;
        $cleanup->run();
        self::assertSame([1],$native->deleted);
        self::assertSame('expired',$store->get('scope','1')['state']);
        self::assertSame([],$store->get('scope','1')['resource_refs']);
        foreach(['3'=>'uncertain','4'=>'repairing','5'=>'running','6'=>'partial'] as $id=>$state){
            self::assertSame($state,$store->get('scope',(string)$id)['state']);
            self::assertNotEmpty($store->get('scope',(string)$id)['resource_refs']);
            self::assertNotContains((int)$id,$native->read);
        }
        $cleanup->run();
        self::assertSame([1],$native->deleted,'Cleanup is idempotent.');
    }
}
