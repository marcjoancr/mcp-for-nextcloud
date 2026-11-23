<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Contacts;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
use OCA\Mcp\Modules\Contacts\Cards\ContactWriter;
use OCA\Mcp\Modules\Contacts\Deletion\ContactBackup;
use OCA\Mcp\Modules\Contacts\Execution\ContactPlan;
use OCA\Mcp\Modules\Contacts\Merging\ContactMerger;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class ContactMergeTest extends TestCase
{
    public function testMergesUnknownPropertiesAndParametersOnlyAfterEveryConflictIsChosenAndBackedUp(): void
    {
        [$merger, $dav, $backup, $state] = $this->fixture();
        $args = $this->arguments();

        $initial = $merger->previewMerge($args, 'alice');
        self::assertSame(['name'], array_column($initial['conflicts'], 'field'));
        self::assertSame(['primary.vcf', 'other.vcf'], array_column($initial['conflicts'][0]['candidates'], 'uri'));
        self::assertSame([], $state->operations);
        self::assertArrayHasKey('plan_state', $initial);

        $resolvedArgs = $args + ['choices' => ['name' => 'other.vcf']];
        $plan = $merger->previewMerge($resolvedArgs, 'alice');
        self::assertSame(['name'], array_column($plan['conflicts'], 'field'));
        self::assertTrue($plan['conflicts'][0]['resolved']);
        self::assertTrue($plan['ready']);
        self::assertSame('other.vcf', $plan['choices']['name']);
        self::assertArrayHasKey('plan_state', $plan);

        $result = $merger->merge($resolvedArgs + [
            'confirm' => true,
            PlanState::ARGUMENT => $plan[PlanState::ARGUMENT],
        ], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($data['merged']);
        self::assertSame([], $data['sourcesRemaining']);
        self::assertSame('primary-uid', $data['uid']);
        self::assertSame('primary.vcf', $data['uri']);
        self::assertSame(2, count($data['backupPaths']));
        self::assertStringContainsString('FN:Other Name', $data['vcard']);
        self::assertStringContainsString('EMAIL;TYPE=HOME;X-KEEP=first:one@example.invalid', $data['vcard']);
        self::assertStringContainsString('EMAIL;TYPE=WORK;X-KEEP=second:two@example.invalid', $data['vcard']);
        self::assertStringContainsString('X-PRIVATE;X-PARAM=one:alpha', $data['vcard']);
        self::assertStringContainsString('X-PRIVATE;X-PARAM=two:beta', $data['vcard']);
        self::assertSame(['backup', 'backup', 'update', 'delete'], $state->operations);
        self::assertArrayNotHasKey('other.vcf', $state->rows);
    }

    public function testUnverifiedPrimaryReadbackLeavesEverySourceAndReturnsBackupReferences(): void
    {
        [$merger, $dav, , $state] = $this->fixture(true);
        $dav->expects(self::never())->method('delete');
        $args = $this->arguments() + ['choices' => ['name' => 'other.vcf']];
        $plan = $merger->previewMerge($args, 'alice');
        $result = $merger->merge($args + [
            'confirm' => true,
            PlanState::ARGUMENT => $plan[PlanState::ARGUMENT],
        ], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($data['partial']);
        self::assertFalse($data['merged']);
        self::assertFalse($data['sourcesDeleted']);
        self::assertSame(['other.vcf'], $data['sourcesRemaining']);
        self::assertCount(2, $data['backupPaths']);
        self::assertSame(['backup', 'backup', 'update'], $state->operations);
        self::assertArrayHasKey('other.vcf', $state->rows);
    }

    public function testRejectsMissingConflictChoiceBeforeSavingAnyBackup(): void
    {
        [$merger, , $backup, $state] = $this->fixture();
        $plan = $merger->previewMerge($this->arguments(), 'alice');
        $backup->expects(self::never())->method('save');
        $this->expectException(ToolFailure::class);
        $merger->merge($this->arguments() + [
            'confirm' => true,
            PlanState::ARGUMENT => $plan[PlanState::ARGUMENT],
        ], 'alice');
    }

    public function testBatchReturnsOnlyIncompleteGroupsWithFreshEtagReferencesForRetry(): void
    {
        [$merger, $dav, , $state] = $this->fixture(true);
        $group = $this->arguments() + ['choices' => ['name' => 'other.vcf']];
        $preview = $merger->previewBatch(['groups' => [$group]], 'alice');
        $dav->expects(self::never())->method('delete');

        $result = $merger->mergeBatch([
            'groups' => [$group],
            'confirm' => true,
            PlanState::ARGUMENT => $preview[PlanState::ARGUMENT],
        ], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([0], $data['retryGroupIndexes']);
        self::assertSame([0], array_column($data['retryGroups'], 'index'));
        self::assertTrue($data['retryGroups'][0]['requiresReview']);
        self::assertSame('etag-merged', $data['retryGroups'][0]['group']['contacts'][0]['etag']);
        self::assertSame(['other.vcf'], $data['groups'][0]['sourcesRemaining']);
        self::assertCount(2, $state->rows);
    }

    public function testBatchRejectsOverlappingGroupsBeforePreparingWrites(): void
    {
        [$merger] = $this->fixture();
        $group = $this->arguments();
        $this->expectException(\InvalidArgumentException::class);
        $merger->previewBatch(['groups' => [$group, $group]], 'alice');
    }

    public function testRetryDropsChoicesPointingToSourcesAlreadyDeletedByAPartialGroup(): void
    {
        [$merger, , , $state] = $this->fixture(false, true);
        $group = $this->arguments();
        $group['contacts'][] = ['uri' => 'last.vcf', 'etag' => 'etag-last'];
        $group['choices'] = ['name' => 'other.vcf'];
        $preview = $merger->previewBatch(['groups' => [$group]], 'alice');

        $result = $merger->mergeBatch([
            'groups' => [$group],
            'confirm' => true,
            PlanState::ARGUMENT => $preview[PlanState::ARGUMENT],
        ], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $retry = $data['retryGroups'][0];

        self::assertSame(['other.vcf'], $data['groups'][0]['sourcesDeletedUris']);
        self::assertSame(['last.vcf'], $data['groups'][0]['sourcesRemaining']);
        self::assertSame(['name'], $retry['invalidatedChoices']);
        self::assertSame([], $retry['group']['choices']);
        self::assertSame(['primary.vcf', 'last.vcf'], array_column($retry['group']['contacts'], 'uri'));
        self::assertArrayNotHasKey('other.vcf', $state->rows);
        self::assertArrayHasKey('last.vcf', $state->rows);
    }

    public function testConflictingPreferredEmailsRequireAnExplicitSourceChoice(): void
    {
        [$merger] = $this->fixture(false, false, true);
        $plan = $merger->previewMerge($this->arguments(), 'alice');

        self::assertSame(['name', 'preferredEmail'], array_column($plan['conflicts'], 'field'));
        $resolved = $merger->previewMerge($this->arguments() + [
            'choices' => ['name' => 'other.vcf', 'preferredEmail' => 'other.vcf'],
        ], 'alice');
        self::assertTrue($resolved['ready']);
        self::assertSame('other.vcf', $resolved['conflicts'][0]['choice']);
        self::assertSame('two@example.invalid', $resolved['after']['preferredEmail']);
    }

    public function testRequiredFormattedNameCannotBeCleared(): void
    {
        [$merger] = $this->fixture();
        $this->expectException(\InvalidArgumentException::class);
        $merger->previewMerge($this->arguments() + ['choices' => ['name' => 'clear']], 'alice');
    }

    public function testMixedVCardVersionsAreRejectedBeforeAnyBackup(): void
    {
        [$merger, , , $state] = $this->fixture();
        $state->rows['other.vcf']['data'] = str_replace('VERSION:3.0', 'VERSION:4.0', $state->rows['other.vcf']['data']);
        $this->expectException(ToolFailure::class);
        $merger->previewMerge($this->arguments(), 'alice');
    }

    public function testBackupFailurePreventsWritingOrDeletingAndReturnsAlreadySavedBackup(): void
    {
        [$merger, , , $state] = $this->fixture(false, false, false, true);
        $args = $this->arguments() + ['choices' => ['name' => 'other.vcf']];
        $plan = $merger->previewMerge($args, 'alice');
        $result = $merger->merge($args + [
            'confirm' => true,
            PlanState::ARGUMENT => $plan[PlanState::ARGUMENT],
        ], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($data['partial']);
        self::assertFalse($data['primaryVerified']);
        self::assertSame(['other.vcf'], $data['sourcesRemaining']);
        self::assertSame(['backup', 'backup'], $state->operations);
        self::assertCount(1, $data['backupPaths']);
        self::assertArrayHasKey('other.vcf', $state->rows);
    }

    private function arguments(): array
    {
        return [
            'addressbook' => '/remote.php/dav/addressbooks/users/alice/people/',
            'keepUri' => 'primary.vcf',
            'contacts' => [
                ['uri' => 'primary.vcf', 'etag' => 'etag-primary'],
                ['uri' => 'other.vcf', 'etag' => 'etag-other'],
            ],
        ];
    }

    private function fixture(
        bool $corruptReadback = false,
        bool $failSecondDelete = false,
        bool $preferredPair = false,
        bool $failBackup = false,
    ): array
    {
        $rows = [
            'primary.vcf' => [
                'uri' => 'primary.vcf',
                'etag' => 'etag-primary',
                'data' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:primary-uid\r\nFN:Primary Name\r\n"
                    . "EMAIL;TYPE=HOME;X-KEEP=first:one@example.invalid\r\n"
                    . "X-PRIVATE;X-PARAM=one:alpha\r\nEND:VCARD\r\n",
            ],
            'other.vcf' => [
                'uri' => 'other.vcf',
                'etag' => 'etag-other',
                'data' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:other-uid\r\nFN:Other Name\r\n"
                    . "EMAIL;TYPE=WORK;X-KEEP=second:two@example.invalid\r\n"
                    . "X-PRIVATE;X-PARAM=two:beta\r\nEND:VCARD\r\n",
            ],
        ];
        if ($failSecondDelete) {
            $rows['last.vcf'] = [
                'uri' => 'last.vcf',
                'etag' => 'etag-last',
                'data' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:last-uid\r\nFN:Last Name\r\n"
                    . "EMAIL;TYPE=HOME;X-KEEP=last:third@example.invalid\r\nEND:VCARD\r\n",
            ];
        }
        if ($preferredPair) {
            $rows['primary.vcf']['data'] = str_replace(
                'EMAIL;TYPE=HOME;X-KEEP=first:',
                'EMAIL;TYPE=HOME,PREF;X-KEEP=first:',
                $rows['primary.vcf']['data'],
            );
            $rows['other.vcf']['data'] = str_replace(
                'EMAIL;TYPE=WORK;X-KEEP=second:',
                'EMAIL;TYPE=WORK,PREF;X-KEEP=second:',
                $rows['other.vcf']['data'],
            );
        }
        $state = (object) ['rows' => $rows, 'operations' => [], 'backupCount' => 0];
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn([[
            'id' => 7,
            'uri' => 'people',
            'displayName' => 'People',
            'ownerPrincipal' => 'principals/users/alice',
            'readOnly' => false,
        ]]);
        $store->method('cards')->with(7)->willReturnCallback(static function () use ($state): array { return array_values($state->rows); });
        $store->method('card')->with(7, self::anything())->willReturnCallback(
            static function (int $id, string $uri) use ($state): ?array { return $state->rows[$uri] ?? null; }
        );
        $dav = $this->createMock(ContactDav::class);
        $dav->method('update')->willReturnCallback(
            static function (string $userId, string $bookUri, string $uri, string $etag, string $vcard) use ($state, $corruptReadback): DavResult {
                $state->operations[] = 'update';
                if ($corruptReadback) {
                    $vcard = str_replace('FN:Other Name', 'FN:Wrong Name', $vcard);
                }
                $state->rows[$uri] = ['uri' => $uri, 'etag' => 'etag-merged', 'data' => $vcard];
                return new DavResult(204);
            }
        );
        $dav->method('delete')->willReturnCallback(
            static function (string $userId, string $bookUri, string $uri, string $etag) use ($state, $failSecondDelete): DavResult {
                $state->operations[] = 'delete';
                if ($failSecondDelete && $uri === 'last.vcf') {
                    throw new ToolFailure('simulated source delete failure');
                }
                unset($state->rows[$uri]);
                return new DavResult(204);
            }
        );
        $backup = $this->createMock(ContactBackup::class);
        $backup->method('save')->willReturnCallback(
            static function (string $userId, string $bookName, string $name, string $data) use ($state, $failBackup): string {
                $state->operations[] = 'backup';
                $state->backupCount++;
                if ($failBackup && $state->backupCount === 2) {
                    throw new ToolFailure('simulated backup verification failure');
                }
                return '/Backups/' . $name . '.vcf';
            }
        );
        $cards = new ContactCard();
        $repository = new ContactRepository($store);
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('secret', '')->willReturn('test-secret');
        $plans = new ContactPlan(new SharedCollectionNotice($this->createMock(IUserManager::class)), $cards);
        $writer = new ContactWriter($dav, $repository, $cards);
        $merger = new ContactMerger(
            new ContactAccess($store),
            $store,
            $repository,
            $cards,
            $plans,
            $writer,
            $backup,
            $dav,
            new PlanState($config),
        );
        return [$merger, $dav, $backup, $state];
    }
}
