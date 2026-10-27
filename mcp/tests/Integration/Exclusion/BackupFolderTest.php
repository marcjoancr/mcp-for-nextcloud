<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real personal, external, groupfolder and received-share backup-location regressions. */
final class BackupFolderTest extends ExclusionTestCase {
    /** @return iterable<string, array{string}> arenas provisioned by the official harness */
    public static function arenasForNamesakes(): iterable {
        yield 'personal subfolder' => ['home'];
        yield 'external mount' => ['external'];
        yield 'groupfolder mount' => ['groupfolder'];
    }

    /**
     * A namesake under a normal directory must remain visible despite a hidden sibling original.
     *
     * @param string $arena provisioned storage arena
     * @return void
     */
    #[DataProvider('arenasForNamesakes')]
    public function testNestedNamesakeIsOrdinary(string $arena): void {
        $original = $this->makeFile($arena, 'secret.txt', 'private');
        $copy = $this->makeFile($arena, 'MCP backups/secret.txt.20261024-120000.bak', 'ordinary');
        $this->tag($original);
        $this->exclude();
        self::assertTrue($this->newGuard()->isVisible($copy));
    }

    /**
     * The actual root backup keeps inheriting the hidden original's exclusion.
     *
     * @return void
     */
    public function testRootMirrorStillHidesAnExcludedOriginal(): void {
        $original = $this->makeFile('home', 'secret.txt', 'private');
        $relative = 'MCP backups/' . $this->run;
        $this->later(fn () => $this->deleteQuietly(self::ALICE, $relative));
        $copy = $this->ensureFolder(self::ALICE, $relative)->newFile('secret.txt.20261024-120000.bak', 'copy');
        $this->tag($original);
        $this->exclude();
        self::assertFalse($this->newGuard()->isVisible($copy));
        self::assertSame(VisibilityGuard::EXPLAINED_BACKUP, $this->newGuard()->explain($copy)['reason']);
    }

    /**
     * A folder called MCP backups inside a received share retains ordinary folder semantics.
     *
     * @return void
     */
    public function testNamesakeInsideReceivedShareIsOrdinary(): void {
        $original = $this->makeFile('home', 'secret.txt', 'private');
        $this->makeFile('home', 'MCP backups/secret.txt.20261024-120000.bak', 'ordinary');
        $this->shareToUser($this->userFolder(self::ALICE)->get($this->run), self::ALICE, self::BOB);
        $this->tag($original);
        $this->exclude();
        self::assertContains('secret.txt.20261024-120000.bak', $this->listNames($this->mcp(self::BOB), '/' . $this->run . '/MCP backups') ?? [], 'the ordinary file is listed inside the received share');
    }

    /** @return iterable<string, array{bool}> canonical backup shares with ordinary mounted names */
    public static function sharedBackupKinds(): iterable {
        yield 'backup subtree' => [false];
        yield 'individual backup file' => [true];
    }

    #[DataProvider('sharedBackupKinds')]
    public function testSharedBackupWithoutReservedMountNameStillHidesOriginal(bool $individual): void {
        $original = $this->makeFile('home', 'secret.txt', 'private');
        $relative = 'MCP backups/' . $this->run;
        $this->later(fn () => $this->deleteQuietly(self::ALICE, $relative));
        $folder = $this->ensureFolder(self::ALICE, $relative);
        $leaf = 'secret.txt.20261024-120000.bak';
        $copy = $folder->newFile($leaf, 'copy');
        $share = $this->shareToUser($individual ? $copy : $folder, self::ALICE, self::BOB);
        $target = '/' . ltrim($share->getTarget(), '/') . ($individual ? '' : '/' . $leaf);
        $recipient = $this->mcp(self::BOB);
        $this->exclude();
        // Fresh HTTP requests observe the recipient's mount created by the share.
        self::assertContains($leaf, $this->listNames($recipient, dirname($target)) ?? []);
        // Exclude after the copy/share: copied tags cannot accidentally satisfy this proof.
        $this->tag($original);
        self::assertNotContains($leaf, $this->listNames($recipient, dirname($target)) ?? []);
        $this->assertHiddenFromRead($recipient, $target, 'the shared backup of an excluded original');
    }
}
