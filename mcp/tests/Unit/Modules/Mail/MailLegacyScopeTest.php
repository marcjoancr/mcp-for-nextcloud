<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Access\{MailAccessGuard,MailOperationAuthorization};
use OCA\Mcp\Modules\Mail\Integration\{MailServices,MailUnavailableException};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\{FilesScope,FilesAccessGuard};
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use OCA\Mcp\Tests\Unit\{SqliteDatabase,InMemoryConfig};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class MailLegacyScopeTest extends TestCase {
    public static function cases():array {return ['unrestricted'=>[null,true],'empty'=>[[],false],'whole account restricted'=>[['account:3'],false],'folder restricted'=>[['mailbox:7'],false]];}
    #[DataProvider('cases')]
    public function testLegacyIdentityCapturePreservesUnrestrictedOwnerApiAndNeverGuessesRestrictedProvenance(?array $items,bool $allowed):void {
        $sql=new SqliteDatabase($this,[new \OCA\Mcp\Migration\Version002700Date20261107000000()]);$journal=new OperationJournal(new OperationsMapper($sql->connection()),new PlanState((new InMemoryConfig())->mock($this)),new ConnectionContext(),$this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class), new \OCA\Mcp\Tests\Support\OperationActors());
        $scope=new ScopeStub();if($items!==null)$scope->restrict('mail',$items);$native=$this->createMock(MailServices::class);$native->method('boundedMetadata')->willThrowException(new MailUnavailableException());
        $native->expects($allowed?self::atLeastOnce():self::never())->method('mailManager')->willReturn(new class {
            public function getMessage(string $uid,int $id):object {return new class {public function getMailboxId():int{return 7;}};}
            public function getMailbox(string $uid,int $id):object {return new class {public function getAccountId():int{return 3;}};}
        });
        $native->expects($allowed?self::atLeastOnce():self::never())->method('accountService')->willReturn(new class {public function find(string $uid,int $id):object{return new class {public function getId():int{return 3;}public function getUserId():string{return 'alice';}};}});
        $auth=new MailOperationAuthorization(new MailAccessGuard($scope),$native,$journal,$this->createMock(GrantPolicy::class),$this->createMock(FilesScope::class),$this->createMock(FilesAccessGuard::class),$this->createMock(\OCP\Files\IRootFolder::class),new \OCA\Mcp\Modules\Mail\Integration\MailTagVisibility($native,new MailAccessGuard($scope)),$this->createMock(\OCA\Mcp\Modules\Mail\Access\MailDeliveryOrigin::class));
        $args=['account_id'=>3,'message_id'=>9];$plan=$journal->plan('mail_set_message_flag','alice',$args,['plan_state'=>'proof']);$captured=false;
        $r=$journal->execute('mail_set_message_flag','alice',$args+['operation_id'=>$plan['operation_id'],'plan_state'=>'proof'],function()use($auth,$args,&$captured):array {$auth->capture('alice','mail_set_message_flag',$args);$captured=true;return [];});
        self::assertSame($allowed,$captured);self::assertSame($allowed,($r['operation']['state']??null)==='succeeded');
        if($allowed)self::assertSame([['account'=>3,'mailbox'=>7]],$journal->privateResources('alice',$plan['operation_id'])['authorization']['mail']);
    }
    public function testLegacyAccountFailuresKeepThePublicContractWithoutMaskingOtherFailures():void {
        require_once __DIR__.'/../../../stubs/OCA/Mail/Exception/ClientException.php';
        $sql=new SqliteDatabase($this,[new \OCA\Mcp\Migration\Version002700Date20261107000000()]);$journal=new OperationJournal(new OperationsMapper($sql->connection()),new PlanState((new InMemoryConfig())->mock($this)),new ConnectionContext(),$this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class), new \OCA\Mcp\Tests\Support\OperationActors());
        $scope=new ScopeStub();$native=$this->createMock(MailServices::class);
        $native->method('boundedMetadata')->willThrowException(new MailUnavailableException());
        $native->method('accountService')->willReturn(new class {
            public function find(string $uid,int $id):object {
                if(in_array($id,[5,999],true))throw new \OCA\Mail\Exception\ClientException('PRIVATE_NATIVE_ACCOUNT_CANARY');
                if($id===99)throw new \RuntimeException('Unrelated native storage failure');
                return new class($uid,$id){public function __construct(private string $uid,private int $id){}public function getId():int{return $this->id;}public function getUserId():string{return $this->id===6?'bob':$this->uid;}};
            }
        });
        $auth=new MailOperationAuthorization(new MailAccessGuard($scope),$native,$journal,$this->createMock(GrantPolicy::class),$this->createMock(FilesScope::class),$this->createMock(FilesAccessGuard::class),$this->createMock(\OCP\Files\IRootFolder::class),new \OCA\Mcp\Modules\Mail\Integration\MailTagVisibility($native,new MailAccessGuard($scope)),$this->createMock(\OCA\Mcp\Modules\Mail\Access\MailDeliveryOrigin::class));
        foreach([5,999]as$id){
            try{$auth->references('alice','mail_list_mailboxes',['account_id'=>$id]);self::fail('Unavailable account admitted');}
            catch(\Throwable $e){self::assertInstanceOf(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class,$e);self::assertSame('Mail account not found.',$e->getMessage());self::assertNull($e->getPrevious());}
        }
        self::assertSame([], $auth->references('alice','mail_list_mailboxes',['account_id'=>3])['mail']);
        try{$auth->references('alice','mail_list_mailboxes',['account_id'=>6]);self::fail('Wrong native owner admitted');}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){self::assertTrue(true);}
        try{$auth->references('alice','mail_list_mailboxes',['account_id'=>99]);self::fail('Storage error hidden');}catch(\RuntimeException $e){self::assertSame('Unrelated native storage failure',$e->getMessage());}
        $scope->restrict('mail',[]);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$auth->references('alice','mail_list_mailboxes',['account_id'=>3]);
    }

}
