<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailMutationTarget,MailMutationSession};
use OCA\Mcp\Modules\Mail\Mutations\NativeMutationRunner;
use OCA\Mcp\Db\MailProbeSlotsMapper;
use OCA\Mcp\Mcp\Operations\{OperationJournal,OperationStore,BeforeEffectFailure};
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailMutationSessionTest extends TestCase {
    public function testChangedTargetRefusesBeforeDelegationOrWorkerAdmission():void {
        $approved=['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true,'profile_revision'=>'p','cache_revision'=>'c'];
        $target=$this->createMock(MailMutationTarget::class);$target->method('snapshot')->willReturn(['request'=>array_replace($approved,['generation'=>'43','profile_revision'=>'new-profile'])]);
        $runner=$this->createMock(NativeMutationRunner::class);$runner->expects(self::never())->method('run');
        $slots=$this->createMock(MailProbeSlotsMapper::class);$slots->expects(self::never())->method('reserve');
        $store=$this->createMock(OperationStore::class);$store->expects(self::never())->method('checkpointNative');$clock=$this->createStub(ITimeFactory::class);
        $journal=new OperationJournal($store,new PlanState((new InMemoryConfig())->mock($this)),new ConnectionContext(),$clock, new \OCA\Mcp\Tests\Support\OperationActors());
        $session=new MailMutationSession($target,$journal,$runner,$slots,$clock);$this->expectException(BeforeEffectFailure::class);$session->setFlag('alice',$approved);
    }
}
