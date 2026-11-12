<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../../stubs/OCA/Mail/Exception/ClientException.php';
require_once dirname(__DIR__,5).'/native/mail-guard/src/Exception/DraftAdmissionFailure.php';
final class MailManualAuthorizationPermitTest extends TestCase {
    private function permit():object {
        $path=dirname(__DIR__,5).'/native/mail-guard/src/Authorization/ManualAuthorizationPermit.php';self::assertFileExists($path);require_once $path;
        return new \OCA\Mail\Authorization\ManualAuthorizationPermit('alice',str_repeat('a',64),7,hash('sha256','native-state'));
    }
    private function account(string $access,string $refresh,string $uid='alice',int $id=7):object {
        return new class($access,$refresh,$uid,$id){
            public function __construct(private string $access,private string $refresh,private string $uid,private int $id){}
            public function getUserId():string{return $this->uid;}
            public function getId():int{return $this->id;}
            public function getMailAccount():object{return new class($this->access,$this->refresh){
                public function __construct(private string $access,private string $refresh){}
                public function getOauthAccessToken():string{return $this->access;}
                public function getOauthRefreshToken():string{return $this->refresh;}
                public function getOauthTokenTtl():int{return 5000;}
            };}
        };
    }
    public function testExistingTokensWithoutManualSuccessNeverEstablishProof():void {
        $p=$this->permit();self::assertFalse($p->isAccepted());self::assertFalse($p->matchesPersisted($this->account('old-access','old-refresh')));
    }
    public function testOnlyAcceptedOwnedExactPersistedTokensMatchAndNeverSerialize():void {
        $p=$this->permit();$access=bin2hex(random_bytes(24));$refresh=bin2hex(random_bytes(24));$a=$this->account($access,$refresh);
        $p->accept($a);self::assertTrue($p->isAccepted());self::assertTrue($p->matchesPersisted($a));
        self::assertFalse($p->matchesPersisted($this->account('intervening-token',$refresh)));
        self::assertFalse($p->matchesPersisted($this->account($access,$refresh,'bob')));
        self::assertStringNotContainsString($access,json_encode($p,JSON_THROW_ON_ERROR));self::assertStringNotContainsString($refresh,json_encode($p,JSON_THROW_ON_ERROR));
    }
    public function testDifferentAccountCannotMarkManualSuccess():void {
        $p=$this->permit();$this->expectException(\OCA\Mail\Exception\DraftAdmissionFailure::class);$p->accept($this->account('access','refresh','alice',8));
    }
}
