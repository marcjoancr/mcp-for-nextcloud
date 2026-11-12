<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Modules\Mail\Integration\{MailRuntimePairs,MailCapabilities,NativeDraftLinks,NativeSetupEvidence,MailAliasBoundary};
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCP\{Server,IConfig};
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailRuntimeQualificationTest extends IntegrationTestCase {
    public function testRuntimeAdmissionAndAllAdaptersAgreeWithoutNativeSideEffects():void {
        if(getenv('MCP_IT_WITH_MAIL')!=='1')self::markTestSkipped('Pinned Mail cell only.');
        $core=Server::get(IConfig::class)->getSystemValueString('version','');
        $mail=Server::get(IAppManager::class)->getAppVersion('mail');
        $candidate=getenv('MCP_IT_MAIL_RUNTIME_PHASE')==='candidate';
        $guard=getenv('MCP_IT_MAIL_GUARD')==='1';
        $admitted=getenv('MCP_IT_NC')==='35'||($guard&&!$candidate);
        self::assertSame($admitted,MailRuntimePairs::matches($core,$mail));
        $before=MailNativeMutationActor::safety(MailActor::ALICE);
        $caps=Server::get(MailCapabilities::class)->forUser(MailActor::ALICE);
        self::assertSame($admitted?'characterized':'unverified',$caps['evidence']);
        self::assertSame('disabled',$caps['capabilities']['send']['status']);
        foreach(['flags','move','tags','refresh','create_mailbox','create_tag'] as $key){
            if(!$admitted)self::assertSame('unverified',$caps['capabilities'][$key]['status'],$key);
            elseif($guard)self::assertSame('verified',$caps['capabilities'][$key]['status'],$key);
        }
        self::assertSame($guard&&$admitted,Server::get(NativeDraftLinks::class)->available());
        self::assertSame($guard&&$admitted,Server::get(NativeSetupEvidence::class)->available());
        $alias=true;try{Server::get(MailAliasBoundary::class)->requireSupported();}catch(BeforeEffectFailure){$alias=false;}
        self::assertSame($admitted,$alias);
        self::assertSame($before,MailNativeMutationActor::safety(MailActor::ALICE));
    }
}
