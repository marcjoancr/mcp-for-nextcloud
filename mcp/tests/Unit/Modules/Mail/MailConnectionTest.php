<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Support\Mail\MailSettingsFixture;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Db\{OperationsMapper,MailProbeSlotsMapper};
use OCA\Mcp\Migration\{Version002700Date20261107000000,Version002700Date20261108000000};
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Modules\Mail\Connection\{MailConnection,NativeConnectionProbe,ProbeTicket};
use OCA\Mcp\Platform\Process\NativeProbeProcess;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailConnectionTest extends TestCase {
    use MailSettingsFixture;
    public function testIndependentConfirmedProbesHaveAHardBudgetRateLimitAndNoReplay(): void {
        [$g,,,, $states]=$this->fixture();$context=new ConnectionContext();$clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000(),new Version002700Date20261108000000()]);$store=new OperationsMapper($db->connection());$j=new OperationJournal($store,$states,$context,$clock, new \OCA\Mcp\Tests\Support\OperationActors());
        $calls=[];$runner=$this->createMock(NativeProbeProcess::class);
        $runner->method('run')->willReturnCallback(static function(array $command,string $cwd,array $payload,int $budget)use(&$calls):array{
            $calls[]=$payload['protocol'];self::assertSame(10000,$budget);self::assertStringNotContainsString('SECRET',json_encode([$command,$payload]));return ['status'=>$payload['protocol']==='imap'?'ok':'failed','category'=>$payload['protocol']==='imap'?'authenticated':'connection_or_authentication_failed','transcript'=>'SECRET-PROVIDER'];
        });
        $probe=new NativeConnectionProbe($runner,new ProbeTicket($states,$clock),$context,static fn():array=>['command'=>[PHP_BINARY,'/fixture/occ','mcp:mail-connection-probe'],'cwd'=>'/fixture']);
        $s=new MailConnection($g,$states,$j,new MailProbeSlotsMapper($db->connection()),$probe,$clock);$args=['account_id'=>4,'protocols'=>['imap','smtp']];
        for($i=0;$i<5;$i++){
            $p=$j->plan('mail_test_connection','alice',$args,$s->preview($args,'alice'));$a=$args+['plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];
            $r=$j->execute('mail_test_connection','alice',$a,fn():array=>$s->probe($a,'alice'));
            self::assertSame('ok',$r['protocols']['imap']['status']??null,json_encode($r));self::assertSame('failed',$r['protocols']['smtp']['status']);
            $repeat=$j->execute('mail_test_connection','alice',$a,fn():array=>throw new \LogicException('Replayed probe'));
            self::assertStringNotContainsString('SECRET',json_encode([$r,$store->get($j->scopeKey('alice'),$p['operation_id'])]));
            self::assertSame($r['protocols'],$repeat['operation']['protocols']??null);
            self::assertSame($r['protocols'],$j->get('alice',$p['operation_id'])['protocols']??null);
            $store->compact(2000);
            self::assertSame($r['protocols'],$j->get('alice',$p['operation_id'])['protocols']??null);
        }
        self::assertCount(10,$calls);
        $p=$j->plan('mail_test_connection','alice',$args,$s->preview($args,'alice'));$a=$args+['plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];
        try{$j->execute('mail_test_connection','alice',$a,fn():array=>$s->probe($a,'alice'));self::fail('Rate limit ignored');}
        catch(\OCA\Mcp\Mcp\Operations\BeforeEffectFailure){self::assertCount(10,$calls);self::assertSame('failed_before_effect',$j->get('alice',$a['operation_id'])['state']);}
    }
    public function testSourceRevocationBetweenProtocolsPreservesObservedResultsWithoutAnotherProbe():void {
        [$g,,,, $states]=$this->fixture();$context=new ConnectionContext();$clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000(),new Version002700Date20261108000000()]);
        $j=new OperationJournal(new OperationsMapper($db->connection()),$states,$context,$clock, new \OCA\Mcp\Tests\Support\OperationActors());$allowed=true;$calls=0;
        $setup=$this->createMock(\OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup::class);
        $setup->method('diagnosticContext')->willReturnCallback(static function()use(&$allowed):array{
            if(!$allowed)throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Source revoked');
            return ['source_operation_id'=>'setup','source_tool'=>'mail_configure_connection','source_tools'=>['mail_configure_connection','mail_read_connection'],'profile_revision'=>'revision','credential_change'=>'unverified'];
        });
        $native=$this->createMock(NativeConnectionProbe::class);$native->method('run')->willReturnCallback(static function()use(&$allowed,&$calls):array{$calls++;$allowed=false;return ['status'=>'ok','category'=>'authenticated'];});
        $s=new MailConnection($g,$states,$j,new MailProbeSlotsMapper($db->connection()),$native,$clock,$setup);
        $args=['account_id'=>4,'protocols'=>['imap','smtp'],'setup_operation_id'=>'setup','intent'=>[]];
        $p=$j->plan('mail_test_connection','alice',$args,$s->preview($args,'alice'));$a=$args+['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];
        $failure=null;try{$r=$j->execute('mail_test_connection','alice',$a,fn():array=>$s->probe($a,'alice'));}catch(\OCA\Mcp\Mcp\Operations\BeforeEffectFailure $e){$failure=$e;}
        self::assertNull($failure,'A completed first diagnostic must not be erased as failed-before-effect');
        self::assertSame(1,$calls);self::assertSame('ok',$r['protocols']['imap']['status']);
        self::assertSame('policy_refused',$r['protocols']['smtp']['category']);self::assertSame('unverified',$r['authentication_observation']['status']);
        self::assertSame('unverified',$r['authentication_observation']['credential_change']);
        self::assertTrue($j->execute('mail_test_connection','alice',$a,static fn():array=>throw new \LogicException('No replay probe'))['operation']['replayed']);
        self::assertSame(1,$calls);
    }

}
