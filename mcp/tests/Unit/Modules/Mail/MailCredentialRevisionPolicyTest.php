<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../../stubs/OCA/Mail/Exception/ClientException.php';
require_once dirname(__DIR__,5).'/native/mail-guard/src/Exception/DraftAdmissionFailure.php';
final class MailCredentialRevisionPolicyTest extends TestCase {
    private function policy():object {
        $p=dirname(__DIR__,5).'/native/mail-guard/src/Db/CredentialRevision.php';self::assertFileExists($p);require_once $p;
        return new class extends \stdClass {use \OCA\Mail\Db\CredentialRevision;public function changes(array $fields):bool{return $this->mcpCredentialFieldsChanged($fields);}public function finish(\OCP\AppFramework\Db\Entity $entity,int $revision):object{return $this->mcpCredentialWritten($entity,$revision);}};
    }
    public function testSignatureAndPreferencesDoNotInvalidateCredentialEvidence():void {
        self::assertFalse($this->policy()->changes(['signature'=>true,'editorMode'=>true,'showSubscribedOnly'=>true]));
    }
    public function testManualAndBackgroundAuthorizationOrIdentityWritesAdvanceEvidence():void {
        foreach(['name','inboundPassword','outboundPassword','oauthAccessToken','oauthRefreshToken','oauthTokenTtl','authMethod','inboundHost','outboundUser','userId'] as $field)self::assertTrue($this->policy()->changes([$field=>true]));
    }
    public function testCallersCannotSetThePrivateRevision():void {
        $p=$this->policy();$this->expectException(\OCA\Mail\Exception\DraftAdmissionFailure::class);$p->changes(['mcpAuthRevision'=>true]);
    }
    public function testPersistedCredentialFieldsDoNotReappearInLaterMailboxSynchronization():void {
        $policy=$this->policy();
        $entity=new class extends \OCP\AppFramework\Db\Entity {
            protected $inboundPassword;protected $lastMailboxSync;protected $mcpAuthRevision=0;
            public function __construct(){$this->addType('lastMailboxSync','integer');$this->addType('mcpAuthRevision','integer');}
            public function mcpCaptureAuthRevision(int $revision):void {$this->mcpAuthRevision=$revision;}
        };
        $value=bin2hex(random_bytes(24));$entity->setInboundPassword($value);
        $policy->finish($entity,7);self::assertSame([],$entity->getUpdatedFields());self::assertSame($value,$entity->getInboundPassword());self::assertSame(7,$entity->getMcpAuthRevision());
        $entity->setLastMailboxSync(100);self::assertFalse($policy->changes($entity->getUpdatedFields()));
    }

}
