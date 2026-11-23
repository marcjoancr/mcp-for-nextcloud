<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Contacts;

use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Duplicates\ContactDuplicateFinder;
use PHPUnit\Framework\TestCase;

final class ContactDuplicateFinderTest extends TestCase {
    public function testFindsSameEmailOnceAcrossVisibleBooksAndNormalizesInternationalPhone(): void {
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->with('principals/users/alice')->willReturn([
            ['id' => 1, 'uri' => 'first', 'displayName' => 'First', 'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false],
            ['id' => 2, 'uri' => 'second', 'displayName' => 'Second', 'ownerPrincipal' => 'principals/users/bob', 'readOnly' => true],
        ]);
        $store->method('cards')->willReturnCallback(static fn(int $id) => match ($id) {
            1 => [self::row('one', 'Pat', "EMAIL:Person@example.test\r\nEMAIL:person@example.test\r\nTEL:+34 600 123 456")],
            2 => [self::row('two', 'Pat P', "EMAIL:person@example.test\r\nTEL:0034 600 123456")],
            default => throw new \LogicException('Only visible books may be scanned'),
        });
        $finder = new ContactDuplicateFinder(new ContactAccess($store), $store, new ContactCard());
        $result = json_decode($finder->execute([], 'alice')['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['email', 'phone'], array_column($result['groups'], 'reason'));
        self::assertSame(['one.vcf', 'two.vcf'], array_column($result['groups'][0]['contacts'], 'uri'));
        self::assertSame('+34600123456', $result['groups'][1]['value']);
    }

    public function testNameAndOrganizationAreAReviewHintForHomonyms(): void {
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn([
            ['id' => 1, 'uri' => 'first', 'displayName' => 'First', 'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false],
        ]);
        $store->method('cards')->willReturn([
            self::row('one', 'Pilar  Vidal', 'ORG:Acme'),
            self::row('two', 'pilar vidal', 'ORG:ACME'),
            self::row('other', 'Pilar Vidal', 'ORG:Other'),
        ]);
        $finder = new ContactDuplicateFinder(new ContactAccess($store), $store, new ContactCard());
        $result = json_decode($finder->execute([], 'alice')['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['name_and_organization'], array_column($result['groups'], 'reason'));
        self::assertSame(['one.vcf', 'two.vcf'], array_column($result['groups'][0]['contacts'], 'uri'));
        self::assertFalse($result['groups'][0]['truncated']);
    }

    private static function row(string $uid, string $name, string $properties): array {
        return ['uri' => $uid . '.vcf', 'etag' => 'etag-' . $uid,
            'data' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$uid\r\nFN:$name\r\n$properties\r\nEND:VCARD\r\n"];
    }
}
