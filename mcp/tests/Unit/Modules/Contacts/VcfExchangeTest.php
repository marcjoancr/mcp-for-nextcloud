<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Contacts;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
use OCA\Mcp\Modules\Contacts\Deletion\ContactBackup;
use OCA\Mcp\Modules\Contacts\Exchange\VcfBundle;
use OCA\Mcp\Modules\Contacts\Exchange\VcfExchange;
use OCA\Mcp\Tests\Support\Files\FilesGuards;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

final class VcfExchangeTest extends TestCase {
    private const BOOK = '/remote.php/dav/addressbooks/users/alice/contacts/';
    private const CARD = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:old-contact\r\nFN:Before\r\nX-CUSTOM:value\r\nEND:VCARD\r\n";
    private string $source = self::CARD;
    private array $cards = [];
    private int $writes = 0;
    private int $scans = 0;
    private int $updates = 0;
    private array $events = [];
    private bool $stripUnknownAfterUpdate = false;
    private ?string $failingUid = null;

    public function testBackupImportRequiresPreviewAndDoesNotDuplicateAnExistingUid(): void {
        $exchange = $this->exchange();
        $args = ['addressbook' => self::BOOK, 'path' => '/backup.vcf'];
        $plan = $exchange->preview($args, 'alice');
        self::assertSame('create', $plan['contacts'][0]['status']);
        self::assertSame(0, $this->writes);
        $result = $this->json($exchange->import($args + ['plan_state' => $plan['plan_state']], 'alice'));
        self::assertSame('created', $result['contacts'][0]['status']);
        self::assertSame(1, $this->writes);
        $uri = $result['contacts'][0]['uri'];
        $export = $this->json($exchange->export(['addressbook' => self::BOOK, 'uris' => [$uri]], 'alice'));
        self::assertSame('text/vcard', $export['contentType']);
        self::assertStringContainsString('X-CUSTOM:value', $export['vcard']);

        $repeat = $exchange->preview($args, 'alice');
        self::assertSame('already_present', $repeat['contacts'][0]['status']);
        $exchange->import($args + ['plan_state' => $repeat['plan_state']], 'alice');
        self::assertSame(1, $this->writes);
    }

    public function testChangingTheFileAfterPreviewReturnsAnUpdatedPlan(): void {
        $exchange = $this->exchange();
        $args = ['addressbook' => self::BOOK, 'path' => '/backup.vcf'];
        $plan = $exchange->preview($args, 'alice');
        $this->source = str_replace('FN:Before', 'FN:After', self::CARD);
        try {
            $exchange->import($args + ['plan_state' => $plan['plan_state']], 'alice');
            self::fail('Changed source must be previewed again');
        } catch (PlanChanged $change) {
            self::assertSame('After', $change->plan['contacts'][0]['name']);
            self::assertSame(0, $this->writes);
        }
    }

    public function testAnUnexpectedNativeFailureKeepsOtherImportResults(): void {
        $this->source = self::CARD . str_replace('old-contact', 'refused-contact', self::CARD)
            . str_replace('old-contact', 'later-contact', self::CARD);
        $this->failingUid = 'refused-contact';
        $exchange = $this->exchange();
        $args = ['addressbook' => self::BOOK, 'path' => '/backup.vcf'];
        $plan = $exchange->preview($args, 'alice');
        $result = $this->json($exchange->import($args + ['plan_state' => $plan['plan_state']], 'alice'));
        self::assertSame(['created', 'unverified', 'created'], array_column($result['contacts'], 'status'));
        self::assertSame(2, $this->writes);
    }

    public function testHiddenVcfAndHiddenAncestorAreRejectedBeforeReadingOrScanning(): void {
        $tree = new FakeTree($this, '/alice/files');
        $directId = $tree->addFile('/alice/files/secret_direct.vcf', self::CARD);
        $folderId = $tree->addFolder('/alice/files/SecretFolder');
        $tree->addFile('/alice/files/SecretFolder/secret_ancestral.vcf', self::CARD);
        $config = new InMemoryConfig();
        $config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $tags = $this->createMock(ISystemTagObjectMapper::class);
        $tags->method('getTagIdsForObjects')->willReturnCallback(static function (array $ids) use ($directId, $folderId): array {
            $result = array_fill_keys(array_map(strval(...), $ids), []);
            $result[(string)$directId] = ['999'];
            $result[(string)$folderId] = ['999'];
            return $result;
        });
        $tag = $this->createMock(ISystemTag::class);
        $tag->method('getId')->willReturn('999');
        $tagManager = $this->createMock(ISystemTagManager::class);
        $tagManager->method('getTagsByIds')->willReturn(['999' => $tag]);
        $guard = FilesGuards::unrestricted($this, $config->mock($this), $tags, $tree->filesystemRoot(), $tagManager);
        $exchange = $this->exchange($guard, $tree->filesystemRoot());

        foreach (['/secret_direct.vcf', '/SecretFolder/secret_ancestral.vcf'] as $path) {
            try {
                $exchange->preview(['addressbook' => self::BOOK, 'path' => $path], 'alice');
                self::fail('A hidden VCF source must be refused');
            } catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $failure) {
                self::assertSame(\OCA\Mcp\Platform\FileAccess\FileAccessMessages::notFound(), $failure->getMessage());
            }
        }

        self::assertSame([], $tree->opened, 'The source must be denied before fopen or VCF parsing.');
        self::assertSame(0, $this->scans, 'The import must not scan the destination address book first.');
        self::assertSame(0, $this->writes, 'The import must not put a contact after a hidden source denial.');
    }

    public function testExplicitReconciliationShowsFieldDiffPreservesOtherPropertiesAndBacksUpBeforeUpdate(): void {
        $existing = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:old-contact\r\nFN:Before\r\nTITLE:Engineer\r\nEMAIL;TYPE=HOME:old@example.com\r\nTEL;TYPE=CELL:555-0100\r\nCATEGORIES:Friends\r\nX-CUSTOM:local-only\r\nEND:VCARD\r\n";
        $this->cards['existing.vcf'] = ['uri' => 'existing.vcf', 'etag' => 'existing-etag', 'data' => $existing];
        $this->source = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:old-contact\r\nFN:After\r\nTITLE:Engineer\r\nEMAIL;TYPE=WORK:new@example.com\r\nEND:VCARD\r\n";
        $backup = $this->createMock(ContactBackup::class);
        $backup->expects(self::once())->method('save')->willReturnCallback(function (string $user, string $book, string $name, string $data) use ($existing): string {
            self::assertSame(['alice', 'Contacts', 'Before', $existing], [$user, $book, $name, $data]);
            $this->events[] = 'backup';
            return '/MCP backups/Contacts/Contacts/existing.vcf';
        });
        $this->events = [];
        $exchange = $this->exchange(backup: $backup);
        $arguments = ['addressbook' => self::BOOK, 'path' => '/backup.vcf'];
        $plan = $exchange->previewReconcile($arguments, 'alice');

        self::assertSame('update', $plan['contacts'][0]['status'], json_encode($plan['contacts'][0]));
        self::assertSame(['Before', 'After'], [$plan['contacts'][0]['changes']['name']['before'], $plan['contacts'][0]['changes']['name']['after']]);
        self::assertSame([['TYPE' => 'HOME']], $plan['contacts'][0]['changes']['emails']['beforeParameters']);
        self::assertSame([['TYPE' => 'WORK']], $plan['contacts'][0]['changes']['emails']['afterParameters']);
        self::assertContains('title', $plan['contacts'][0]['unchangedFields']);
        self::assertSame(true, $plan['backup']['required']);
        self::assertSame(0, $this->updates);

        $result = $this->json($exchange->reconcile($arguments + ['plan_state' => $plan['plan_state']], 'alice'));
        self::assertSame('updated', $result['contacts'][0]['status']);
        self::assertSame('/MCP backups/Contacts/Contacts/existing.vcf', $result['contacts'][0]['backupPath']);
        self::assertSame(['backup', 'update'], $this->events);
        $saved = $this->cards['existing.vcf']['data'];
        self::assertStringContainsString('FN:After', $saved);
        self::assertStringContainsString('TITLE:Engineer', $saved);
        self::assertStringContainsString('EMAIL;TYPE=WORK:new@example.com', $saved);
        self::assertStringContainsString('TEL;TYPE=CELL:555-0100', $saved);
        self::assertStringContainsString('CATEGORIES:Friends', $saved);
        self::assertStringContainsString('X-CUSTOM:local-only', $saved);
    }

    public function testAmbiguousUidAndBackupFailureNeverUpdateExistingCards(): void {
        $existing = str_replace('old-contact', 'same-uid', self::CARD);
        $this->cards['one.vcf'] = ['uri' => 'one.vcf', 'etag' => 'one-etag', 'data' => $existing];
        $this->cards['two.vcf'] = ['uri' => 'two.vcf', 'etag' => 'two-etag', 'data' => str_replace('FN:Before', 'FN:Other', $existing)];
        $this->source = str_replace('old-contact', 'same-uid', str_replace('FN:Before', 'FN:Incoming', self::CARD));
        $backup = $this->createMock(ContactBackup::class);
        $backup->expects(self::never())->method('save');
        $exchange = $this->exchange(backup: $backup);
        $arguments = ['addressbook' => self::BOOK, 'path' => '/backup.vcf'];
        $plan = $exchange->previewReconcile($arguments, 'alice');
        self::assertSame('ambiguous', $plan['contacts'][0]['status']);
        $exchange->reconcile($arguments + ['plan_state' => $plan['plan_state']], 'alice');
        self::assertSame(0, $this->updates);

        $this->cards = ['one.vcf' => ['uri' => 'one.vcf', 'etag' => 'one-etag', 'data' => $existing]];
        $backupFailure = $this->createMock(ContactBackup::class);
        $backupFailure->method('save')->willThrowException(new \RuntimeException('backup unavailable'));
        $exchange = $this->exchange(backup: $backupFailure);
        $plan = $exchange->previewReconcile($arguments, 'alice');
        $result = $this->json($exchange->reconcile($arguments + ['plan_state' => $plan['plan_state']], 'alice'));
        self::assertSame('backup_failed', $result['contacts'][0]['status']);
        self::assertSame(0, $this->updates);
    }

    public function testReconciliationApprovalBindsBothSourceFileAndExistingTargetState(): void {
        $this->cards['existing.vcf'] = ['uri' => 'existing.vcf', 'etag' => 'etag-before', 'data' => self::CARD];
        $this->source = str_replace('FN:Before', 'FN:From file', self::CARD);
        $exchange = $this->exchange(backup: $this->createMock(ContactBackup::class));
        $arguments = ['addressbook' => self::BOOK, 'path' => '/backup.vcf'];
        $plan = $exchange->previewReconcile($arguments, 'alice');

        $this->source = str_replace('FN:From file', 'FN:New source', $this->source);
        try {
            $exchange->reconcile($arguments + ['plan_state' => $plan['plan_state']], 'alice');
            self::fail('Changed source bytes require a fresh reconciliation preview.');
        } catch (PlanChanged $changed) {
            self::assertSame('New source', $changed->plan['contacts'][0]['name']);
            self::assertSame(0, $this->updates);
        }

        $plan = $exchange->previewReconcile($arguments, 'alice');
        $this->cards['existing.vcf']['etag'] = 'concurrent-etag';
        try {
            $exchange->reconcile($arguments + ['plan_state' => $plan['plan_state']], 'alice');
            self::fail('Changed target card requires a fresh reconciliation preview.');
        } catch (PlanChanged $changed) {
            self::assertSame('concurrent-etag', $changed->plan['contacts'][0]['etag']);
            self::assertSame(0, $this->updates);
        }
    }

    public function testReconciliationReadbackReportsWhenTheServerDropsAnUnrelatedProperty(): void {
        $this->cards['existing.vcf'] = ['uri' => 'existing.vcf', 'etag' => 'etag-before', 'data' => self::CARD];
        $this->source = str_replace('FN:Before', 'FN:After', self::CARD);
        $this->stripUnknownAfterUpdate = true;
        $backup = $this->createMock(ContactBackup::class);
        $backup->method('save')->willReturn('/MCP backups/Contacts/Before.vcf');
        $exchange = $this->exchange(backup: $backup);
        $arguments = ['addressbook' => self::BOOK, 'path' => '/backup.vcf'];
        $plan = $exchange->previewReconcile($arguments, 'alice');
        $result = $this->json($exchange->reconcile($arguments + ['plan_state' => $plan['plan_state']], 'alice'));

        self::assertSame('unverified', $result['contacts'][0]['status']);
        self::assertSame('/MCP backups/Contacts/Before.vcf', $result['contacts'][0]['backupPath']);
    }

    private function exchange(?FilesAccessGuard $fileAccess = null, ?IRootFolder $files = null, ?ContactBackup $backup = null): VcfExchange {
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->with('principals/users/alice')->willReturn([
            ['id' => 1, 'uri' => 'contacts', 'displayName' => 'Contacts', 'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false],
        ]);
        $store->method('cards')->willReturnCallback(function () {
            $this->scans++;
            return array_values($this->cards);
        });
        $store->method('card')->willReturnCallback(fn ($book, $uri) => $this->cards[$uri] ?? null);
        $dav = $this->createMock(ContactDav::class);
        $dav->method('put')->willReturnCallback(function (string $user, string $book, string $uri, string $vcard): DavResult {
            self::assertSame(['alice', 'contacts'], [$user, $book]);
            self::assertArrayNotHasKey($uri, $this->cards);
            if ($this->failingUid !== null && str_contains($vcard, 'UID:' . $this->failingUid)) {
                throw new \RuntimeException('Unclassified native failure');
            }
            $this->writes++;
            $this->cards[$uri] = ['uri' => $uri, 'etag' => 'contact-etag', 'data' => $vcard];
            return new DavResult(201, null);
        });
        $dav->method('update')->willReturnCallback(function (string $user, string $book, string $uri, string $etag, string $vcard): DavResult {
            self::assertSame(['alice', 'contacts'], [$user, $book]);
            self::assertSame($etag, $this->cards[$uri]['etag'] ?? null);
            $this->events[] = 'update';
            $this->updates++;
            if ($this->stripUnknownAfterUpdate) { $vcard = str_replace("X-CUSTOM:value\r\n", '', $vcard); }
            $this->cards[$uri] = ['uri' => $uri, 'etag' => 'updated-etag-' . $this->updates, 'data' => $vcard];
            return new DavResult(204, null);
        });
        $file = $this->createMock(File::class);
        $file->method('isReadable')->willReturn(true);
        $file->method('getSize')->willReturnCallback(fn () => strlen($this->source));
        $file->method('getEtag')->willReturnCallback(fn () => hash('sha256', $this->source));
        $file->method('fopen')->with('rb')->willReturnCallback(function () {
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $this->source);
            rewind($stream);
            return $stream;
        });
        $root = $files?->getUserFolder('alice') ?? $this->createMock(Folder::class);
        if ($files === null) {
            $root->method('get')->with('backup.vcf')->willReturn($file);
        }
        $files = $this->createMock(IRootFolder::class);
        $files->method('getUserFolder')->with('alice')->willReturn($root);
        $cards = new ContactCard();
        $conf = $this->createMock(IConfig::class);
        return new VcfExchange(
            new ContactAccess($store), $store, new ContactRepository($store), $cards, $dav, new VcfBundle($cards),
            $files ?? $this->createMock(IRootFolder::class), $fileAccess ?? $this->createMock(FilesAccessGuard::class),
            new SharedCollectionNotice($this->createMock(IUserManager::class)), new PlanState($conf), $backup,
        );
    }

    private function json(array $result): array {
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}
