<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Support\Mail\MailSettingsFixture;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Modules\Mail\Connection\{ProbeTicket,NativeConnectionProbeWorker};
use OCA\Mcp\Modules\Mail\Integration\NativeAccountSetupLinks;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\IRemoteHostValidator;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../../stubs/Horde_Mail_Transport_Smtphorde.php';
final class MailConnectionProbeTest extends TestCase {
    use MailSettingsFixture;
    private array $events=[];
    private bool $smtpFails=false;
    private int $now=1000;
    public function testSeparateNativeAuthenticationResultsNeverSubmitAMessageOrExposeServerErrors(): void {
        [$gateway,$entity,,, $states,$services]=$this->fixture();
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(fn():int=>$this->now);
        $tickets=new ProbeTicket($states,$clock);
        $imap=new class($this->events){public function __construct(public array &$events){}public function login():void{$this->events[]='IMAP AUTH';}public function logout():void{$this->events[]='IMAP LOGOUT';}};
        $factory=new class($imap){public function __construct(private object $client){}public function getClient(object $a,bool $cache):object{return $this->client;}};
        $smtp=new class($this->events,$this->smtpFails) extends \Horde_Mail_Transport_Smtphorde {
            public function __construct(public array &$events,public bool &$fails){}
            public function getSMTPObject():object{$this->events[]='SMTP AUTH';if($this->fails)throw new \RuntimeException('SECRET SMTP SERVER TRANSCRIPT');return new class($this->events){public function __construct(public array &$events){}public function logout():void{$this->events[]='SMTP QUIT';}};}
            public function send($to,$headers,$body):void{$this->events[]='SMTP DATA';}
        };
        $smtpFactory=new class($smtp){public function __construct(private object $t){}public function create(object $account):object{return $this->t;}};
        $services->method('clientFactory')->willReturn($factory);$services->method('smtpFactory')->willReturn($smtpFactory);
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$context=new ConnectionContext();$context->set(12);
        $j=new OperationJournal(new OperationsMapper($db->connection()),$states,$context,$clock);
        $hosts=$this->createMock(IRemoteHostValidator::class);$hosts->method('isValid')->willReturn(true);
        $config=$this->createMock(IConfig::class);$config->method('getSystemValueBool')->willReturnCallback(static fn(string $key,bool $default=false):bool=>$key==='app.mail.verify-tls-peer');
        $worker=new NativeConnectionProbeWorker($gateway,$services,new NativeAccountSetupLinks($this->createMock(IURLGenerator::class),$hosts),$config,$tickets,$j,static fn(string $uid,int $connection,array $sources=[],?int $account=null,?\Closure $target=null):bool=>$target===null||$target());
        $args=['account_id'=>4];$p=$j->plan('mail_test_connection','alice',$args,['plan_state'=>'proof']);
        $r=$j->execute('mail_test_connection','alice',$args+['operation_id'=>$p['operation_id'],'plan_state'=>'proof'],function()use($worker,$tickets,$gateway,$j):array{
            $revision=$gateway->readConnection('alice',4)['revision'];
            $imap=$worker->run($tickets->create('alice',4,'imap',12,$revision,$j->activeId('alice')));
            $smtp=$worker->run($tickets->create('alice',4,'smtp',12,$revision,$j->activeId('alice')));
            $this->smtpFails=true;$failed=$worker->run($tickets->create('alice',4,'smtp',12,$revision,$j->activeId('alice')));
            return ['account_id'=>4,'imap'=>$imap,'smtp'=>$smtp,'smtp_failed'=>$failed];
        });
        self::assertSame('ok',$r['imap']['status']);self::assertSame('ok',$r['smtp']['status']);self::assertSame('failed',$r['smtp_failed']['status']);
        self::assertNotContains('SMTP DATA',$this->events);self::assertStringNotContainsString('SECRET',json_encode($r));
        $forged=$tickets->create('alice',4,'imap',12,'revision','operation');$forged['uid']='bob';self::assertFalse($tickets->verify($forged));
        $expired=$tickets->create('alice',4,'imap',12,'revision','operation');$this->now+=16;self::assertFalse($tickets->verify($expired));
    }
}
