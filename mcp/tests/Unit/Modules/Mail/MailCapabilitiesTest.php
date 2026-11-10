<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Modules\Mail\Integration\MailCapabilities;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class MailCapabilitiesTest extends TestCase {
    private function capabilities(string $mail='5.12.5',string $server='35.0.2.2',bool $enabled=true,?\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary $boundary=null): MailCapabilities {
        $native=$this->createMock(MailServices::class);
        $native->method('isEnabledFor')->willReturn($enabled);
        $native->expects(self::never())->method('clientFactory');
        $native->expects(self::never())->method('smtpFactory');
        $native->expects(self::never())->method('accountService');
        $native->expects(self::never())->method('mailManager');
        $apps=$this->createMock(IAppManager::class);$apps->method('getAppVersion')->willReturn($mail);
        $config=$this->createMock(IConfig::class);$config->method('getSystemValueString')->willReturn($server);
        return new MailCapabilities($native,$apps,$config,$boundary);
    }
    public function testKnownPairDoesNotUpgradeLocalReplyIntoAnImapGuarantee(): void {
        $c=$this->capabilities();$r=$c->forUser('alice');
        $c->requireSupported('alice','reply_parent_local');$c->requireSupported('alice','sender_alias_local');
        self::assertSame('characterized',$r['evidence']);
        self::assertSame('verified',$r['capabilities']['reply_parent_local']['status']);
        self::assertSame('unsupported',$r['capabilities']['reply_parent_imap']['status']);
        self::assertSame('unsupported',$r['capabilities']['sender_alias_imap']['status']);
        self::assertSame('unsupported',$r['capabilities']['mutation_session']['status']);
        self::assertSame('native_tag_cache_identity_and_creation_correlation_missing',$r['capabilities']['tags']['reason']);
        self::assertSame('native_sync_owner_lease_and_budget_not_verified',$r['capabilities']['refresh']['reason']);
        self::assertSame('disabled',$r['capabilities']['send']['status']);
    }
    public function testQualifiedDraftSerializationStillNeedsTheKnownNativePairAndNeverEnablesSending():void {
        $boundary=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class);
        $boundary->method('draftsVerified')->willReturn(true);
        $result=$this->capabilities(boundary:$boundary)->forUser('alice');
        self::assertSame('verified',$result['capabilities']['reply_parent_imap']['status']);
        self::assertSame('verified',$result['capabilities']['sender_alias_imap']['status']);
        self::assertSame('disabled',$result['capabilities']['send']['status']);
        $unknown=$this->capabilities(server:'34.0.5',boundary:$boundary)->forUser('alice');
        self::assertSame('unverified',$unknown['capabilities']['reply_parent_imap']['status']);
        self::assertSame('disabled',$unknown['capabilities']['send']['status']);
    }
    public function testRefreshAdmissionDoesNotSpendItsDeadlineOnUnrelatedStorageGates():void {
        $boundary=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class);
        $boundary->expects(self::once())->method('refreshVerified')->willReturn(true);
        foreach(['verified','movesVerified','foldersVerified','tagsVerified','draftsVerified'] as $method)$boundary->expects(self::never())->method($method);
        $this->capabilities(boundary:$boundary)->requireSupported('alice','refresh');
    }
    public function testSelectiveAdmissionMatchesTheFullCapabilityReport():void {
        foreach([[],['verified'],['verified','movesVerified','foldersVerified'],['verified','movesVerified','foldersVerified','tagsVerified','refreshVerified','draftsVerified']] as $proven){
            $boundary=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class);
            foreach(['verified','movesVerified','foldersVerified','tagsVerified','refreshVerified','draftsVerified'] as $method)$boundary->method($method)->willReturn(in_array($method,$proven,true));
            $c=$this->capabilities(boundary:$boundary);$report=$c->forUser('alice');
            foreach($report['capabilities'] as $name=>$row){$admitted=true;try{$c->requireSupported('alice',$name);}catch(ToolFailure){$admitted=false;}self::assertSame($row['status']==='verified',$admitted,$name);}
        }
    }
    public function testUnsupportedMutationCannotBeAdmittedByARecognizedVersion(): void {
        $c=$this->capabilities();
        foreach(['mutation_session','flags','move','tags','create_mailbox','rename_mailbox','subscribe_mailbox','refresh','send','unknown'] as $capability){
            try{$c->requireSupported('alice',$capability);self::fail('Unproven native capability admitted: '.$capability);}catch(ToolFailure){}
        }
    }
    public function testUnsupportedVersionDoesNotHideExistingReadTools(): void {
        $r=$this->capabilities('5.99.1')->forUser('alice');
        self::assertSame('unverified',$r['evidence']);
        self::assertSame('unverified',$r['capabilities']['mutation_session']['status']);
        self::assertSame('disabled',$r['capabilities']['send']['status']);
        // Capability checks are additive; ordinary application availability still admits supported Mail versions.
        $apps=$this->createMock(IAppManager::class);$apps->method('isEnabledForUser')->willReturn(true);$apps->method('getAppVersion')->willReturn('5.99.1');
        self::assertTrue(\OCA\Mcp\Access\AppAvailability\AppEnablement::forUser($apps,'mail',$this->createMock(\OCP\IUser::class)));
    }
    public function testDisabledMailNeverResolvesOptionalNativeServices(): void {
        $r=$this->capabilities(enabled:false)->forUser('alice');
        self::assertSame('unavailable',$r['evidence']);
        self::assertSame('unavailable',$r['capabilities']['mutation_session']['status']);
        self::assertSame('disabled',$r['capabilities']['send']['status']);
    }
}
