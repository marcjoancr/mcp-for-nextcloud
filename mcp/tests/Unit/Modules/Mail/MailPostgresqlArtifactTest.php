<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailRuntimePairs,NativeGuardManifest};
use PHPUnit\Framework\TestCase;
final class MailPostgresqlArtifactTest extends TestCase {
    public function testNewProviderUsesDistinctImmutableArtifact():void {
        self::assertSame('mcp-mail-mutations-v12',NativeGuardManifest::DATA['contract']);
        self::assertSame('5.12.5.12',NativeGuardManifest::DATA['build_version']);
        self::assertArrayHasKey('lib/Db/PgSessionLease.php',NativeGuardManifest::DATA['files']);
        self::assertArrayHasKey('lib/Db/BoundedMailMetadata.php',NativeGuardManifest::DATA['files']);
        $helper=dirname(__DIR__,5).'/native/mail-guard/src/Db/BoundedMailMetadata.php';
        self::assertSame(hash_file('sha256',$helper),NativeGuardManifest::DATA['files']['lib/Db/BoundedMailMetadata.php']);
        self::assertSame('mcp-mail-mutations-v9',NativeGuardManifest::LEGACY_POSTGRESQL_DATA['contract']);
        self::assertSame('5.12.5.9',NativeGuardManifest::LEGACY_POSTGRESQL_DATA['build_version']);
        self::assertArrayNotHasKey('lib/Db/BoundedMailMetadata.php',NativeGuardManifest::LEGACY_POSTGRESQL_DATA['files']);
        $legacy=json_decode((string)file_get_contents(dirname(__DIR__,5).'/native/mail-guard/postgresql-v9-manifest.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame($legacy,NativeGuardManifest::LEGACY_POSTGRESQL_DATA);
        self::assertSame('mcp-mail-mutations-v8',NativeGuardManifest::LEGACY_RUNTIME_DATA['contract']);
        self::assertSame('5.12.5.8',NativeGuardManifest::LEGACY_RUNTIME_DATA['build_version']);
    }
    public function testNewBuildDoesNotExtendUnqualifiedCorePairs():void {
        self::assertTrue(MailRuntimePairs::matches('35.0.2.2','5.12.5.9'));
        self::assertTrue(MailRuntimePairs::matches('35.0.2.2','5.12.5.10'));
        foreach(['33.0.10','34.0.5','35.0.3','36.0.0']as$core)self::assertFalse(MailRuntimePairs::matches($core,'5.12.5.10'));
    }
}
