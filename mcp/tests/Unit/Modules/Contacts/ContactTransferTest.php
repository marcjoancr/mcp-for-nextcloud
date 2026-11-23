<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Contacts;

use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
use OCA\Mcp\Modules\Contacts\Cards\ContactWriter;
use OCA\Mcp\Modules\Contacts\Deletion\ContactBackup;
use OCA\Mcp\Modules\Contacts\Execution\ContactPlan;
use OCA\Mcp\Modules\Contacts\Relocation\ContactTransfer;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCP\IUserManager;
use OCP\IConfig;
use RuntimeException;
use PHPUnit\Framework\TestCase;

final class ContactTransferTest extends TestCase
{
    public function testCopyCreatesVerifiedNewCardWithoutDeletingSource(): void
    {
        [$transfer, $dav] = $this->fixture();
        $args = [
            'sourceAddressbook' => '/remote.php/dav/addressbooks/users/alice/source/',
            'sourceUri' => 'source.vcf',
            'targetAddressbook' => '/remote.php/dav/addressbooks/users/alice/target/',
        ];
        $plan = $transfer->previewCopy($args, 'alice');
        self::assertSame('contacts_copy_contact', $plan['action']);
        self::assertArrayHasKey(PlanState::ARGUMENT, $plan);
        self::assertArrayNotHasKey('uid', $plan['after']);
        self::assertArrayNotHasKey('properties', $plan['after']);

        $dav->expects(self::never())->method('delete');
        $result = $transfer->copyContact($args + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($data['copied']);
        self::assertNotSame('source-uid', $data['uid']);
        self::assertSame($data['uid'] . '.vcf', $data['uri']);
    }

    public function testCopyCannotBeConfirmedWithoutThePlanStateThatWasShown(): void
    {
        [$transfer, $dav] = $this->fixture();
        $args = [
            'sourceAddressbook' => '/remote.php/dav/addressbooks/users/alice/source/',
            'sourceUri' => 'source.vcf',
            'targetAddressbook' => '/remote.php/dav/addressbooks/users/alice/target/',
        ];
        $plan = $transfer->previewCopy($args, 'alice');
        $dav->expects(self::never())->method('put');

        try {
            $transfer->copyContact($args + ['confirm' => true, PlanState::ARGUMENT => str_repeat('0', 64)], 'alice');
            self::fail('an unreviewed copy plan was accepted');
        } catch (PlanChanged $changed) {
            self::assertSame($plan[PlanState::ARGUMENT], $changed->plan[PlanState::ARGUMENT]);
        }
    }

    public function testMoveKeepsSourceWhenDestinationReadbackDoesNotMatchPreparedCard(): void
    {
        [$transfer, $dav, $backup] = $this->fixture(true);
        $args = [
            'sourceAddressbook' => '/remote.php/dav/addressbooks/users/alice/source/',
            'sourceUri' => 'source.vcf',
            'targetAddressbook' => '/remote.php/dav/addressbooks/users/alice/target/',
        ];
        $plan = $transfer->previewMove($args, 'alice');
        $dav->expects(self::never())->method('delete');
        $backup->expects(self::never())->method('save');

        $result = $transfer->moveContact($args + [
            'etag' => 'source-etag',
            'confirm' => true,
            PlanState::ARGUMENT => $plan[PlanState::ARGUMENT],
        ], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($data['moved']);
        self::assertTrue($data['partial']);
        self::assertFalse($data['sourceDeleted']);
        self::assertFalse($data['destinationVerified']);
    }

    public function testMoveBacksUpBeforeDeletingSourceAndKeepsUid(): void
    {
        [$transfer, $dav, $backup, $state] = $this->fixture();
        $args = [
            'sourceAddressbook' => '/remote.php/dav/addressbooks/users/alice/source/',
            'sourceUri' => 'source.vcf',
            'targetAddressbook' => '/remote.php/dav/addressbooks/users/alice/target/',
        ];
        $plan = $transfer->previewMove($args, 'alice');
        $backup->expects(self::once())->method('save')->with('alice', 'Source', 'Alice', self::anything())->willReturn('/Backups/source.vcf');
        $dav->expects(self::once())->method('delete')->with('alice', 'source', 'source.vcf', 'source-etag')
            ->willReturnCallback(static function () use ($state): DavResult {
                $state->source = null;
                return new DavResult(204);
            });

        $result = $transfer->moveContact($args + [
            'etag' => 'source-etag',
            'confirm' => true,
            PlanState::ARGUMENT => $plan[PlanState::ARGUMENT],
        ], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($data['moved']);
        self::assertSame('source-uid', $data['uid']);
        self::assertSame('/Backups/source.vcf', $data['backupPath']);
        self::assertStringStartsWith('moved-', $data['uri']);
        self::assertTrue($data['sourceVerifiedAbsent']);
    }

    public function testMoveReturnsVerifiedReceiptWhenDeleteThrowsAfterRemovingSource(): void
    {
        [$transfer, , , $state] = $this->fixture(false, 'throw-after');
        $args = [
            'sourceAddressbook' => '/remote.php/dav/addressbooks/users/alice/source/',
            'sourceUri' => 'source.vcf',
            'targetAddressbook' => '/remote.php/dav/addressbooks/users/alice/target/',
        ];
        $plan = $transfer->previewMove($args, 'alice');
        $result = $transfer->moveContact($args + [
            'etag' => 'source-etag',
            'confirm' => true,
            PlanState::ARGUMENT => $plan[PlanState::ARGUMENT],
        ], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($data['moved']);
        self::assertTrue($data['sourceDeleted']);
        self::assertTrue($data['sourceVerifiedAbsent']);
        self::assertSame('/Backups/source.vcf', $data['backupPath']);
        self::assertNull($state->source);
    }

    public function testMoveReturnsPartialReceiptWhenDeleteThrowsAndSourceRemains(): void
    {
        [$transfer, , , $state] = $this->fixture(false, 'throw-before');
        $args = [
            'sourceAddressbook' => '/remote.php/dav/addressbooks/users/alice/source/',
            'sourceUri' => 'source.vcf',
            'targetAddressbook' => '/remote.php/dav/addressbooks/users/alice/target/',
        ];
        $plan = $transfer->previewMove($args, 'alice');
        $result = $transfer->moveContact($args + [
            'etag' => 'source-etag',
            'confirm' => true,
            PlanState::ARGUMENT => $plan[PlanState::ARGUMENT],
        ], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($data['moved']);
        self::assertTrue($data['partial']);
        self::assertTrue($data['sourceVerifiedPresent']);
        self::assertSame('/Backups/source.vcf', $data['backupPath']);
        self::assertNotNull($state->source);
    }

    public function testMoveVerifiesTheSourceDisappearedEvenWhenDeleteReturnedSuccess(): void
    {
        [$transfer, , , $state] = $this->fixture(false, 'leave');
        $args = [
            'sourceAddressbook' => '/remote.php/dav/addressbooks/users/alice/source/',
            'sourceUri' => 'source.vcf',
            'targetAddressbook' => '/remote.php/dav/addressbooks/users/alice/target/',
        ];
        $plan = $transfer->previewMove($args, 'alice');
        $result = $transfer->moveContact($args + [
            'etag' => 'source-etag',
            'confirm' => true,
            PlanState::ARGUMENT => $plan[PlanState::ARGUMENT],
        ], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($data['moved']);
        self::assertTrue($data['partial']);
        self::assertTrue($data['destinationVerified']);
        self::assertTrue($data['sourceVerifiedPresent']);
        self::assertNotNull($state->source);
        self::assertSame('/Backups/source.vcf', $data['backupPath']);
    }

    private function fixture(bool $corruptDestination = false, string $deleteMode = 'delete'): array
    {
        $source = ['uri' => 'source.vcf', 'etag' => 'source-etag', 'data' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:source-uid\r\nFN:Alice\r\nEND:VCARD\r\n"];
        $state = (object) ['source' => $source, 'target' => null];
        $books = [
            ['id' => 1, 'uri' => 'source', 'displayName' => 'Source', 'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false],
            ['id' => 2, 'uri' => 'target', 'displayName' => 'Target', 'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false],
        ];
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn($books);
        $store->method('cards')->willReturnCallback(static function (int $bookId) use ($state): array {
            return $bookId === 1 ? ($state->source === null ? [] : [$state->source]) : ($state->target === null ? [] : [$state->target]);
        });
        $store->method('card')->willReturnCallback(static function (int $bookId, string $uri) use ($state): ?array {
            if ($bookId === 1 && $uri === 'source.vcf') {
                return $state->source;
            }
            if ($bookId === 2 && $state->target !== null && $uri === $state->target['uri']) {
                return $state->target;
            }
            return null;
        });
        $dav = $this->createMock(ContactDav::class);
        $dav->method('put')->willReturnCallback(static function (string $uid, string $bookUri, string $uri, string $data) use ($state, $corruptDestination): DavResult {
            if ($corruptDestination) {
                $data = str_replace('FN:Alice', 'FN:Different Person', $data);
            }
            $state->target = ['uri' => $uri, 'etag' => 'target-etag', 'data' => $data];
            return new DavResult(201);
        });
        $dav->method('delete')->willReturnCallback(static function () use ($state, $deleteMode): DavResult {
            if ($deleteMode === 'throw-before') {
                throw new RuntimeException('simulated uncertain DELETE');
            }
            if ($deleteMode !== 'leave') {
                $state->source = null;
            }
            if ($deleteMode === 'throw-after') {
                throw new RuntimeException('simulated response failure after DELETE');
            }
            return new DavResult(204);
        });
        $backup = $this->createMock(ContactBackup::class);
        $backup->method('directory')->willReturn('/Backups/Contacts/Source');
        $backup->method('save')->willReturn('/Backups/source.vcf');
        $cards = new ContactCard();
        $repository = new ContactRepository($store);
        $plans = new ContactPlan(new SharedCollectionNotice($this->createMock(IUserManager::class)), $cards);
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('secret', '')->willReturn('test-secret');
        $writer = new ContactWriter($dav, $repository, $cards);
        $transfer = new ContactTransfer(new ContactAccess($store), $store, $repository, $cards, $plans, $writer, $backup, $dav, new PlanState($config));
        return [$transfer, $dav, $backup, $state];
    }

}
