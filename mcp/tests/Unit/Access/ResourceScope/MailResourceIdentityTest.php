<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;
use PHPUnit\Framework\TestCase;
final class MailResourceIdentityTest extends TestCase {
    public function testTypedScopeRoundTripsWithoutNumericCollision(): void {
        $fx = new ScopeFixture($this);
        $fx->db->beginTransaction();
        $fx->scopes->replace('alice', 'mail', ['mailbox:9', 'account:10', 'account:9'], '1');
        $fx->db->commit();
        self::assertSame(['account:9','account:10','mailbox:9'], $fx->scopes->forModule('alice','mail')['items']);
        self::assertSame(['account:9','account:10','mailbox:9'], $fx->scopes->forUser('alice')['mail']['items']);
    }
    public function testReceiptCodecPreservesTypedIdentitiesAndOldNumericBytes(): void {
        $value = PolicyEventPayload::scopeValue(['mailbox:9','account:10','account:9'], 'mail');
        self::assertSame('["account:9","account:10","mailbox:9"]', $value);
        self::assertSame(['account:9','account:10','mailbox:9'], PolicyEventPayload::scopeItems($value, 'mail'));
        self::assertSame('["9","10"]', PolicyEventPayload::scopeValue(['10','9']));
        foreach (['["account:01"]','["account:0"]','["mailbox:9223372036854775808"]','["9"]','["folder:9"]','["account:9","account:9"]'] as $bad) {
            self::assertFalse(PolicyEventPayload::scopeItems($bad, 'mail'), $bad);
        }
        self::assertFalse(PolicyEventPayload::scopeItems('["account:9"]', 'files'));
    }
}
