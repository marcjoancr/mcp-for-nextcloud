<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../../stubs/OCA/Mail/Exception/ClientException.php';
require_once dirname(__DIR__,5).'/native/mail-guard/src/Exception/DraftAdmissionFailure.php';
final class MailSetupContextTest extends TestCase {
    private function context(array $changes=[]):object {
        $path=dirname(__DIR__,5).'/native/mail-guard/src/Authorization/SetupContext.php';self::assertFileExists($path);require_once $path;
        return new \OCA\Mail\Authorization\SetupContext(array_replace(['id'=>str_repeat('a',64),'source_key'=>str_repeat('b',64),'user_id'=>'alice','kind'=>'configure_connection','target_id'=>7,'intent_json'=>'{}','phase'=>'active','activated_at'=>100,'created_at'=>100,'expires_at'=>86500],$changes));
    }
    private function profile():array {return ['name'=>'Owned Mail','email'=>'owner@example.test','auth_method'=>'password','imap'=>['host'=>'imap.example.test','port'=>993,'tls'=>'ssl','user'=>'owner'],'smtp'=>['host'=>'smtp.example.test','port'=>465,'tls'=>'ssl','user'=>'owner']];}
    public function testForeignExpiredOrUnopenedContextCannotAuthorizeNativeWrite():void {
        $p=$this->profile();$c=$this->context();self::assertTrue($c->allows('alice','configure_connection',7,$p,100));
        self::assertFalse($c->allows('bob','configure_connection',7,$p,100));self::assertFalse($c->allows('alice','configure_connection',7,$p,86501));
        self::assertFalse($this->context(['phase'=>'registered','activated_at'=>null])->allows('alice','configure_connection',7,$p,100));
        self::assertFalse($this->context(['phase'=>'consumed'])->allows('alice','configure_connection',7,$p,100));
    }
    public function testDifferentAccountKindOrRequestedProfileCannotReuseHandoff():void {
        $p=$this->profile();$c=$this->context(['intent_json'=>'{"smtp":{"host":"selected.example.test"}}']);
        self::assertFalse($c->allows('alice','configure_connection',7,$p,100));$p['smtp']['host']='selected.example.test';
        self::assertTrue($c->allows('alice','configure_connection',7,$p,100));
        self::assertFalse($c->allows('alice','configure_connection',8,$p,100));self::assertFalse($c->allows('alice','create_account',7,$p,100));
    }
    public function testOnlyActualCreateActionMayBindAnUnassignedTarget():void {
        $c=$this->context(['kind'=>'create_account','target_id'=>null]);$p=$this->profile();
        self::assertTrue($c->allows('alice','create_account',7,$p,100));self::assertFalse($c->allows('alice','configure_connection',7,$p,100));
        self::assertFalse($this->context(['kind'=>'create_account'])->allows('alice','create_account',8,$p,100));
    }
    public function testUnsafeTlsDoesNotProduceEvidence():void {
        $p=$this->profile();$p['imap']['tls']='none';self::assertFalse($this->context()->allows('alice','configure_connection',7,$p,100));
    }
    public function testUnknownIntentFieldsCannotBePersistedAsAContext():void {
        $path=dirname(__DIR__,5).'/native/mail-guard/src/Authorization/SetupContext.php';self::assertFileExists($path);require_once $path;
        $this->expectException(\OCA\Mail\Exception\DraftAdmissionFailure::class);$this->context(['intent_json'=>'{"password":"not-an-accepted-field"}']);
    }
    public function testActualSignedJournalOperationIdsBindToTheirExactOwnerAndScope():void {
        $this->context();$operation=str_repeat('a',32).'.1900000000.'.str_repeat('b',64);$scope=str_repeat('c',64);
        $key=\OCA\Mail\Authorization\SetupContext::sourceKey('alice',$scope,$operation);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$key);
        self::assertNotSame($key,\OCA\Mail\Authorization\SetupContext::sourceKey('bob',$scope,$operation));
        self::assertNotSame($key,\OCA\Mail\Authorization\SetupContext::sourceKey('alice',str_repeat('d',64),$operation));
    }

}
