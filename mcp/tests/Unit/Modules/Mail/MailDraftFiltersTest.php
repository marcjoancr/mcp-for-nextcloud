<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Drafts\MailDraftOperations;
use OCA\Mcp\Modules\Mail\Integration\{MailServices,MailGateway};
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/FakeMailApp.php';
final class MailDraftFiltersTest extends TestCase {
    public function testAccountAndSubjectFiltersApplyBeforePaginationWithoutImap():void {
        $rows=[];foreach([[1,4,'Quarterly report'],[2,5,'Quarterly report'],[3,4,'Other']] as [$id,$account,$subject])$rows[]=new class($id,$account,$subject){public function __construct(private int $id,private int $account,private string $subject){}public function __call(string $method,array $args):mixed{return match($method){'getId'=>$this->id,'getAccountId'=>$this->account,'getSubject'=>$this->subject,'getUpdatedAt'=>1,'getAttachments','getRecipients'=>[],default=>null};}};
        $native=$this->createMock(MailServices::class);$native->method('localMessageMapper')->willReturn(new class($rows){public function __construct(private array $rows){}public function getAllForUser(string $uid,int $type):array{return $this->rows;}});$native->method('localDraftType')->willReturn(1);$native->expects(self::never())->method('clientFactory');
        $accounts=new MailGateway((new FakeMailApp())->withAccount(4,'alice','Work','work@example.test')->withAccount(5,'alice','Personal','personal@example.test'));
        $ops=new MailDraftOperations($native,$accounts,new PlanState((new InMemoryConfig())->mock($this)));$r=$ops->read(['account_id'=>4,'subject_contains'=>'REPORT','limit'=>1],'alice');self::assertCount(1,$r['drafts']);self::assertSame(1,$r['drafts'][0]['id']);self::assertFalse($r['hasMore']);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$ops->read(['account_id'=>99],'alice');
    }
}
