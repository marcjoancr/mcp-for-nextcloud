<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Contacts;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
use OCA\Mcp\Modules\Contacts\Categories\ContactCategoryEditor;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\Dav\Collections\Collection;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class ContactCategoryEditorTest extends TestCase {
    private const BOOK = '/remote.php/dav/addressbooks/users/alice/contacts/';
    private const CARD_A = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:a\r\nFN:Alice A\r\nCATEGORIES:Family,VIP\r\nX-KEEP:one\r\nEND:VCARD\r\n";
    private const CARD_B = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:b\r\nFN:Alice B\r\nCATEGORIES:Family\r\nEND:VCARD\r\n";

    public function testPreviewAndApplyAddPreserveUnrelatedCategoriesAndCardProperties(): void {
        $state = $this->state();
        $editor = $this->editor($state);
        $arguments = $this->arguments('add', 'Friends');
        $plan = $editor->preview($arguments, 'alice');

        self::assertSame(['Family', 'VIP'], $plan['contacts'][0]['before']['categories']);
        self::assertSame(['Family', 'VIP', 'Friends'], $plan['contacts'][0]['after']['categories']);
        self::assertSame(0, $state['updates']);

        $result = $this->json($editor->execute($arguments + ['plan_state' => $plan['plan_state']], 'alice'));
        self::assertSame(['updated', 'updated'], array_column($result['contacts'], 'result'));
        self::assertSame(['Family', 'VIP', 'Friends'], $this->categories($state['rows']['one.vcf']['data']));
        self::assertStringContainsString('X-KEEP:one', $state['rows']['one.vcf']['data']);
        self::assertSame(['Family', 'Friends'], $this->categories($state['rows']['two.vcf']['data']));
    }

    public function testChangedEtagAfterPreviewReturnsChangedPlanWithoutWriting(): void {
        $state = $this->state();
        $editor = $this->editor($state);
        $arguments = $this->arguments('remove', 'Family');
        $plan = $editor->preview($arguments, 'alice');
        $state['rows']['one.vcf']['etag'] = 'concurrent-etag';

        try {
            $editor->execute($arguments + ['plan_state' => $plan['plan_state']], 'alice');
            self::fail('A changed contact must not be written under an old plan.');
        } catch (PlanChanged $changed) {
            self::assertSame('concurrent-etag', $changed->plan['contacts'][0]['etag']);
            self::assertSame(0, $state['updates']);
        }
    }

    public function testOneFailedCardDoesNotHideSuccessfulCardsInTheBatchResult(): void {
        $state = $this->state();
        $state['failedUri'] = 'one.vcf';
        $editor = $this->editor($state);
        $arguments = $this->arguments('rename', 'Family', 'Household');
        $plan = $editor->preview($arguments, 'alice');
        $result = $this->json($editor->execute($arguments + ['plan_state' => $plan['plan_state']], 'alice'));

        self::assertSame(['unverified', 'updated'], array_column($result['contacts'], 'result'));
        self::assertSame(['Family', 'VIP'], $this->categories($state['rows']['one.vcf']['data']));
        self::assertSame(['Household'], $this->categories($state['rows']['two.vcf']['data']));
    }

    private function editor(array &$state): ContactCategoryEditor {
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->with('principals/users/alice')->willReturn([
            ['id' => 1, 'uri' => 'contacts', 'displayName' => 'Contacts', 'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false],
        ]);
        $store->method('card')->willReturnCallback(static function (int $book, string $uri) use (&$state): ?array {
            return $state['rows'][$uri] ?? null;
        });
        $dav = $this->createMock(ContactDav::class);
        $dav->method('update')->willReturnCallback(static function (string $user, string $book, string $uri, string $etag, string $data) use (&$state): DavResult {
            if ($uri === $state['failedUri']) { throw new \RuntimeException('simulated card failure'); }
            if (($state['rows'][$uri]['etag'] ?? null) !== $etag) { throw new \RuntimeException('stale etag'); }
            $state['updates']++;
            $state['rows'][$uri] = ['uri' => $uri, 'etag' => 'next-' . $state['updates'], 'data' => $data];
            return new DavResult(204, null);
        });
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('secret', '')->willReturn('test-secret');
        return new ContactCategoryEditor(
            new ContactAccess($store),
            new ContactRepository($store),
            new ContactCard(),
            $dav,
            new PlanState($config),
            new SharedCollectionNotice($this->createMock(IUserManager::class)),
        );
    }

    private function state(): array {
        return [
            'updates' => 0,
            'failedUri' => null,
            'rows' => [
                'one.vcf' => ['uri' => 'one.vcf', 'etag' => 'etag-a', 'data' => self::CARD_A],
                'two.vcf' => ['uri' => 'two.vcf', 'etag' => 'etag-b', 'data' => self::CARD_B],
            ],
        ];
    }

    private function arguments(string $action, string $category, ?string $replacement = null): array {
        $arguments = [
            'addressbook' => self::BOOK,
            'contacts' => [
                ['uri' => 'one.vcf', 'etag' => 'etag-a'],
                ['uri' => 'two.vcf', 'etag' => 'etag-b'],
            ],
            'action' => $action,
            'category' => $category,
        ];
        if ($replacement !== null) { $arguments['replacement'] = $replacement; }
        return $arguments;
    }

    private function categories(string $data): array {
        $card = (new ContactCard())->parse($data);
        return $card->CATEGORIES?->getParts() ?? [];
    }

    private function json(array $result): array {
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}
