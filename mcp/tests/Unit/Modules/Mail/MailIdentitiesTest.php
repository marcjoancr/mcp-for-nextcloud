<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Accounts\MailIdentities;
use OCA\Mcp\Tests\Support\Mail\MailSettingsFixture;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../../Support/Mail/MailSettingsFixture.php';
final class MailIdentitiesTest extends TestCase {
    use MailSettingsFixture;
    public function testOnlyOwnedSenderFieldsAreExposedAndPaginationPreservesPrimaryIdentity():void {
        [$g,,, $alias,,$native]=$this->fixture();$alias->setAlias('alias@example.test');$alias->setName('Alias');$alias->setSmimeCertificateId('PRIVATE');
        $i=new MailIdentities($g,$native);$p=$i->read(['account_id'=>4,'limit'=>1],'alice');self::assertSame(null,$p['identities'][0]['alias_id']);self::assertTrue($p['hasMore']);$a=$i->read(['account_id'=>4,'offset'=>1],'alice');self::assertSame(3,$a['identities'][0]['alias_id']);self::assertSame('alias@example.test',$a['identities'][0]['email']);self::assertStringNotContainsString('PRIVATE',json_encode($a));self::assertStringNotContainsString('SECRET',json_encode($p));
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$i->read(['account_id'=>4],'bob');
    }
}
