<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\{MailServices,MailGateway,MailSettingsGateway};
use OCA\Mcp\Modules\Mail\Settings\MailSettings;
use OCA\Mcp\Modules\Mail\Signatures\MailSignatures;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../../stubs/OCA/Mail/Exception/ClientException.php';

final class MailSettingsTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Mail\MailSettingsFixture;
    public function testNativeSerializationAndSecretsNeverEscapeTheProjection(): void {
        [$g]=$this->fixture();
        $settings=$g->readSettings('alice',4);$connection=$g->readConnection('alice',4);
        self::assertSame(['editor_mode'=>'html','signature_above_quote'=>true,'show_subscribed_only'=>false],$settings['settings']);
        self::assertSame('imap.example.test',$connection['imap']['host']);
        self::assertStringNotContainsString('SECRET',json_encode([$settings,$connection]));
        self::assertArrayNotHasKey('signature',$settings);
        self::assertArrayNotHasKey('imap',$settings);
    }
    public function testForeignAndMissingAccountsHaveTheSameRefusal(): void {
        [$g]=$this->fixture();$messages=[];
        foreach([['bob',4],['alice',99]] as [$uid,$id]){try{$g->readSettings($uid,$id);self::fail('Account exposed');}catch(ToolFailure $e){$messages[]=$e->getMessage();}}
        self::assertSame($messages[0],$messages[1]);
    }
    public function testOnePreferencePatchPreservesSignatureAuthAndSecurity(): void {
        [$g,$entity,$native,, $states]=$this->fixture();$s=new MailSettings($g,$states);
        $args=['account_id'=>4,'changes'=>['editor_mode'=>'plaintext']];$plan=$s->preview($args,'alice');
        self::assertSame(0,$native->writes);
        $result=$s->write($args+['plan_state'=>$plan['plan_state']],'alice');
        self::assertSame('plaintext',$result['settings']['editor_mode']);
        self::assertSame('<p>Alice</p>',$entity->getSignature());
        self::assertSame('SECRET-IMAP',$entity->getInboundPassword());self::assertTrue($entity->getSmimeSign());
    }
    public function testNativeUiRevisionChangeRequiresANewPlan(): void {
        [$g,$entity,$native,, $states]=$this->fixture();$s=new MailSettings($g,$states);$args=['account_id'=>4,'changes'=>['editor_mode'=>'plaintext']];
        $p=$s->preview($args,'alice');$entity->setShowSubscribedOnly(true);
        try{$s->write($args+['plan_state'=>$p['plan_state']],'alice');self::fail('Stale approval applied');}catch(PlanChanged){self::assertSame(0,$native->writes);}
    }
    public function testAliasMustBelongToTheSelectedAccountAndNullRemovesOnlyThatSignature(): void {
        [$g,$entity,, $alias,$states]=$this->fixture();$s=new MailSignatures($g,$states);
        $a=['account_id'=>4,'alias_id'=>3,'signature'=>null];$p=$s->preview($a,'alice');$s->write($a+['plan_state'=>$p['plan_state']],'alice');
        self::assertNull($alias->getSignature());self::assertSame('<p>Alice</p>',$entity->getSignature());
        $alias->setAccountId(99);$this->expectException(ToolFailure::class);$s->read(['account_id'=>4,'alias_id'=>3],'alice');
    }
    public function testUnknownSecretFieldsAndOversizedSignatureAreRefused(): void {
        [$g,,,, $states]=$this->fixture();$s=new MailSignatures($g,$states);
        $p=$s->preview(['account_id'=>4,'signature'=>str_repeat('x',20000)],'alice');self::assertSame(20000,mb_strlen($p['after']['signature']));
        $this->expectException(\InvalidArgumentException::class);$s->preview(['account_id'=>4,'signature'=>str_repeat('x',20001)],'alice');
    }
}
