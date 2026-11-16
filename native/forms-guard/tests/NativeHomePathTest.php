<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\NativeHomePath;

final class NativeHomePathTest extends TestCase {
    private string $tmp;
    protected function setUp(): void {
        require_once __DIR__ . '/../src/Service/NativeHomePath.php';
        $this->tmp = sys_get_temp_dir() . '/native-home-path-' . bin2hex(random_bytes(8));
        mkdir($this->tmp, 0700);
    }

    protected function tearDown(): void {
        if (is_dir($this->tmp)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
            rmdir($this->tmp);
        }
    }

    public function testProspectiveLocalHomePathDoesNotNeedAResolvedUserFolder(): void {
        self::assertSame('/recipient/files', NativeHomePath::prospectiveFilesPath('recipient'));
    }

    public function testSelectedFolderUsesVirtualPathRatherThanPhysicalHomePath(): void {
        self::assertTrue(NativeHomePath::matchesSelectedFilesPath('/recipient/files', '/recipient/files'));
        self::assertFalse(NativeHomePath::matchesSelectedFilesPath('/srv/nextcloud/data/recipient/files', '/recipient/files'));
        self::assertFalse(NativeHomePath::matchesSelectedFilesPath('/other/files', '/recipient/files'));
    }

    public function testExistingHomeMountIsSelectedFromTheAlreadyMaterializedMountList(): void {
        $root = new class('/') { public function __construct(private string $path) {} public function getMountPoint(): string { return $this->path; } };
        $home = new class('/recipient') { public function __construct(private string $path) {} public function getMountPoint(): string { return $this->path; } };
        self::assertSame($home, NativeHomePath::selectedHomeMount('recipient', [$root, $home]));
        self::assertNull(NativeHomePath::selectedHomeMount('recipient', [$root]));
    }

    public function testMalformedOrAmbiguousMountMetadataRefuses(): void {
        $homeA = new class('/recipient') { public function __construct(private string $path) {} public function getMountPoint(): string { return $this->path; } };
        $homeB = new class('/recipient/') { public function __construct(private string $path) {} public function getMountPoint(): string { return $this->path; } };
        foreach ([[$homeA, $homeB], [new stdClass()]] as $mounts) {
            try {
                NativeHomePath::selectedHomeMount('recipient', $mounts);
                self::fail('Ambiguous native mount metadata was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('Forms Home mount metadata is unavailable', $error->getMessage());
            }
        }
    }

    public function testDefaultLocalHomeIsQualifiedFromUserAndConfigMetadataOnly(): void {
        NativeHomePath::assertDefaultLocalConfig('recipient', '/srv/nextcloud/data/recipient', '/srv/nextcloud/data', false, false);
        foreach ([
            ['recipient', '/srv/nextcloud/data/other', '/srv/nextcloud/data', false, false],
            ['recipient', '/srv/nextcloud/data/recipient', '/srv/nextcloud/data', ['class' => 'objectstore'], false],
            ['recipient', '/srv/nextcloud/data/recipient', '/srv/nextcloud/data', false, ['enabled' => true]],
            ['../recipient', '/srv/nextcloud/data/../recipient', '/srv/nextcloud/data', false, false],
        ] as [$uid, $home, $data, $objectStore, $multiBucket]) {
            try {
                NativeHomePath::assertDefaultLocalConfig($uid, $home, $data, $objectStore, $multiBucket);
                self::fail('Unqualified Home configuration was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('Forms local Home profile is unavailable', $error->getMessage());
            }
        }
    }

    public function testStorageMountSelectionUsesLongestExistingAncestor(): void {
        $home = new class('/recipient') { public function __construct(private string $path) {} public function getMountPoint(): string { return $this->path; } };
        $external = new class('/recipient/files/Shared') { public function __construct(private string $path) {} public function getMountPoint(): string { return $this->path; } };
        self::assertSame($home, NativeHomePath::mountForPath('/recipient/files/Forms', [$home, $external]));
        self::assertSame($external, NativeHomePath::mountForPath('/recipient/files/Shared/subdir', [$home, $external]));
        self::assertNull(NativeHomePath::mountForPath('/recipient/files/Forms', []));
    }

    public function testProspectiveDestinationMetadataDistinguishesClearCollisionAndAmbiguousPaths(): void {
        mkdir($this->tmp . '/recipient', 0700);
        self::assertTrue(NativeHomePath::assertNoPathCollision($this->tmp . '/recipient', 'files/Forms/form-1'));
        mkdir($this->tmp . '/recipient/files', 0700);
        mkdir($this->tmp . '/recipient/files/Forms', 0700);
        mkdir($this->tmp . '/recipient/files/Forms/form-1', 0700);
        try {
            NativeHomePath::assertNoPathCollision($this->tmp . '/recipient', 'files/Forms/form-1');
            self::fail('Existing destination was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Forms destination already exists', $error->getMessage());
        }
        rmdir($this->tmp . '/recipient/files/Forms/form-1');
    }

    public function testSymlinkOrNonDirectoryAncestorIsAmbiguousAndRefused(): void {
        mkdir($this->tmp . '/recipient', 0700);
        symlink($this->tmp, $this->tmp . '/recipient/files');
        try {
            NativeHomePath::assertNoPathCollision($this->tmp . '/recipient', 'files/Forms/form-1');
            self::fail('Symlink destination path was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Forms destination metadata is unavailable', $error->getMessage());
        }
    }

    public function testRecoveryIdentityDigestUsesStableInodeFieldsAndFileContent(): void {
        mkdir($this->tmp . '/owned', 0700);
        $before = NativeHomePath::identityDigest($this->tmp . '/owned');
        file_put_contents($this->tmp . '/owned/child', 'data');
        self::assertSame($before, NativeHomePath::identityDigest($this->tmp . '/owned'));
        $fileBefore = NativeHomePath::identityDigest($this->tmp . '/owned/child', true);
        file_put_contents($this->tmp . '/owned/child', 'changed');
        self::assertNotSame($fileBefore, NativeHomePath::identityDigest($this->tmp . '/owned/child', true));
    }

    public function testRecursiveNativeScanIsAdmittedOnlyAfterTheRootIsProvenEmpty(): void {
        self::assertTrue(NativeHomePath::scanWithinBound(true, 0));
        self::assertFalse(NativeHomePath::scanWithinBound(true, 1));
        self::assertTrue(NativeHomePath::scanWithinBound(false, 100));
        self::assertFalse(NativeHomePath::scanWithinBound(false, 101));
    }

    public function testUnknownOrUnsafeUserIdentityRefusesInsteadOfGuessingAHomePath(): void {
        foreach (['', '../recipient', 'recipient/other', "recipient\0x", "recipient\n"] as $uid) {
            try {
                NativeHomePath::prospectiveFilesPath($uid);
                self::fail('Unsafe identity produced a prospective Home path');
            } catch (RuntimeException $error) {
                self::assertSame('Forms destination identity is unavailable', $error->getMessage());
            }
        }
    }
}
