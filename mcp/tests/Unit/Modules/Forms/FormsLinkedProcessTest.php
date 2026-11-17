<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedExportRunner;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedExportWorker;
use OCA\Mcp\Platform\Process\NativeMutationProcess;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
final class FormsLinkedProcessTest extends TestCase {
    public function testOnlyFormsRunnerGetsTheDedicatedMaximumBudgetProcess():void {
        self::assertSame(NativeMutationProcess::DEFAULT_MAX_BUDGET_MS,20000);
        self::assertSame(FormsLinkedExportRunner::CHILD_BUDGET_MS,NativeMutationProcess::MAX_BUDGET_MS);
        $source=file_get_contents(dirname(__DIR__,4).'/lib/AppInfo/Application.php');
        $start=strpos($source,'FormsLinkedExportRunner::class,');
        $end=strpos($source,'FormsLinkedSweep::class,',$start);
        self::assertIsInt($start);self::assertIsInt($end);
        $formsRunner=substr($source,$start,$end-$start);
        self::assertStringContainsString('new \\OCA\\Mcp\\Platform\\Process\\NativeMutationProcess(\\OCA\\Mcp\\Platform\\Process\\NativeMutationProcess::MAX_BUDGET_MS)',$formsRunner);
        self::assertGreaterThanOrEqual(3,substr_count($source,'$c->get(\\OCA\\Mcp\\Platform\\Process\\NativeMutationProcess::class)'));
    }

    public function testRunnerPassesOnlyOpaqueAssociationWithFormsOnlyThirtySecondCap():void {
        $id=str_repeat('a',64);$process=$this->createMock(NativeMutationProcess::class);
        $command=['/usr/bin/php','/srv/nextcloud/occ','mcp:forms-linked-export','--no-interaction','--no-ansi'];
        $process->expects(self::exactly(2))->method('run')->with($command,'/srv/nextcloud',['association_id'=>$id],30000)->willReturn(['status'=>'unknown']);
        $runner=new FormsLinkedExportRunner($process,static fn():array=>['command'=>$command,'cwd'=>'/srv/nextcloud']);
        self::assertSame(['status'=>'unknown'],$runner->run($id));
        self::assertSame(['status'=>'unknown'],$runner->run($id,60000));
    }
    public function testReadOnlyHealthUsesTheRealBoundedChildAndNeverClaimsAnAssociation():void {
        $process=$this->createMock(NativeMutationProcess::class);
        $process->expects(self::once())->method('run')->with(['/usr/bin/php','/srv/occ','mcp:forms-linked-export'],'/srv',['health'=>true],5000)->willReturn(['status'=>'completed']);
        $runner=new FormsLinkedExportRunner($process,static fn():array=>['command'=>['/usr/bin/php','/srv/occ','mcp:forms-linked-export'],'cwd'=>'/srv']);
        self::assertTrue($runner->available());
        $session=$this->createMock(IUserSession::class);$session->method('getUser')->willReturn(null);
        $worker=new FormsLinkedExportWorker($session,static fn()=>self::fail('Health started an association.'),'cli',static fn():bool=>true);
        self::assertSame(['status'=>'completed'],$worker->run(['health'=>true]));
        self::assertSame(['status'=>'refused'],$worker->run(['health'=>true,'association_id'=>str_repeat('a',64)]));
    }
    public function testHealthRefusalDoesNotTurnAnUnknownRuntimeIntoAnAvailableWriter():void {
        $process=$this->createMock(NativeMutationProcess::class);$process->method('run')->willReturn(['status'=>'unknown']);
        $runner=new FormsLinkedExportRunner($process,static fn():array=>['command'=>['/usr/bin/php','/srv/occ'],'cwd'=>'/srv']);
        self::assertFalse($runner->available());
        $session=$this->createMock(IUserSession::class);$session->method('getUser')->willReturn(null);
        $worker=new FormsLinkedExportWorker($session,static fn()=>self::fail('Health started an association.'),'cli',static fn():bool=>false);
        self::assertSame(['status'=>'refused'],$worker->run(['health'=>true]));
    }
    public function testUnavailableCliAndMalformedIdentityNeverStartAWriter():void {
        $process=$this->createMock(NativeMutationProcess::class);$process->expects(self::never())->method('run');
        $runner=new FormsLinkedExportRunner($process,static fn():?array=>null);
        self::assertSame(['status'=>'refused'],$runner->run(str_repeat('a',64)));
        self::assertSame(['status'=>'refused'],$runner->run('invalid'));
    }
    public function testWorkerRefusesForeignSessionWithoutReplacingOrClearingIt():void {
        $session=$this->createMock(IUserSession::class);$session->method('getUser')->willReturn($this->createMock(IUser::class));
        $session->expects(self::never())->method('setUser');$calls=0;
        $worker=new FormsLinkedExportWorker($session,function()use(&$calls):array {$calls++;return ['state'=>'delivered'];},'cli');
        self::assertSame(['status'=>'refused'],$worker->run(['association_id'=>str_repeat('a',64)]));self::assertSame(0,$calls);
    }
    public function testWorkerUsesOneStepAndReturnsNoPrivateReceiptOrOutputPath():void {
        $session=$this->createMock(IUserSession::class);$session->method('getUser')->willReturn(null);$session->expects(self::never())->method('setUser');
        $id=str_repeat('a',64);$calls=0;
        $worker=new FormsLinkedExportWorker($session,function(string $actual)use($id,&$calls):array {
            self::assertSame($id,$actual);$calls++;return ['state'=>'delivered','file'=>['path'=>'private.csv'],'origin'=>['proof'=>'private-proof']];
        },'cli');
        self::assertSame(['status'=>'completed'],$worker->run(['association_id'=>$id]));self::assertSame(1,$calls);
    }
    public function testWorkerDoesNotRetryUnknownEffectsAndRejectsExtraInput():void {
        $session=$this->createMock(IUserSession::class);$session->method('getUser')->willReturn(null);$calls=0;
        $worker=new FormsLinkedExportWorker($session,function()use(&$calls):array {$calls++;throw new \RuntimeException('lost acknowledgement');},'cli');
        $input=['association_id'=>str_repeat('a',64)];
        self::assertSame(['status'=>'refused'],$worker->run($input+['origin'=>[]]));self::assertSame(0,$calls);
        self::assertSame(['status'=>'unknown'],$worker->run($input));self::assertSame(1,$calls);
    }
    public function testUnexpectedSessionChangeAfterStepCannotProduceCompletedAcknowledgement():void {
        $session=$this->createMock(IUserSession::class);$user=$this->createMock(IUser::class);$current=null;
        $session->method('getUser')->willReturnCallback(static function()use(&$current):?IUser {return $current;});
        $worker=new FormsLinkedExportWorker($session,static function()use(&$current,$user):array {$current=$user;return ['state'=>'delivered'];},'cli');
        self::assertSame(['status'=>'unknown'],$worker->run(['association_id'=>str_repeat('a',64)]));
        self::assertSame($user,$current);
    }
    public function testWebProcessCannotEnterTheWorkerProtocol():void {
        $session=$this->createMock(IUserSession::class);
        $worker=new FormsLinkedExportWorker($session,static fn():array=>throw new \RuntimeException('must not run'),'fpm-fcgi');
        self::assertSame(['status'=>'refused'],$worker->run(['association_id'=>str_repeat('a',64)]));
    }
}
