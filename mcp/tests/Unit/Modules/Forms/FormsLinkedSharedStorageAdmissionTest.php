<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Forms\Mcp\Service\StorageAdmission;
use PHPUnit\Framework\TestCase;

final class FormsLinkedSharedStorageAdmissionTest extends TestCase {
	public function testOnlyMeasuredPinnedNativeWrappersCanPrecedeSharedStorage(): void {
		$guardRoot = dirname(__DIR__, 5) . '/native/forms-guard';
		require_once $guardRoot . '/src/Service/StorageAdmission.php';
		$reflection = new \ReflectionClass(StorageAdmission::class);
		$wrappers = $reflection->getConstant('SHARED_MOUNT_WRAPPERS');
		$pins = $reflection->getConstant('SOURCES');
		self::assertSame([
			'OCA\\Files_Trashbin\\Storage' => 1,
			'OCA\\FilesLock\\Storage\\LockWrapper' => 2,
		], $wrappers);
		foreach ($wrappers as $class => $_rank) {
			self::assertArrayHasKey($class, $pins);
			self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $pins[$class]);
		}

		$source = file_get_contents($guardRoot . '/src/Service/StorageAdmission.php');
		$start = strpos($source, 'private static function sharedStorage(');
		$end = strpos($source, '/** Read an existing lifecycle witness;', $start);
		self::assertIsInt($start);
		self::assertIsInt($end);
		$admission = substr($source, $start, $end - $start);
		self::assertStringContainsString('self::SHARED_MOUNT_WRAPPERS[$class] <= $rank', $admission);
		self::assertStringContainsString('self::source(new \\ReflectionClass($class))', $admission);
		self::assertStringNotContainsString('self::WRAPPERS[$class]', $admission);
		$start = strpos($source, 'private static function shared(');
		$end = strpos($source, 'private static function initializeLinkedSharedStorage(', $start);
		self::assertIsInt($start);
		self::assertIsInt($end);
		$shareAdmission = substr($source, $start, $end - $start);
		self::assertLessThan(strpos($shareAdmission, 'self::initializeLinkedSharedStorage($storage, $fresh, $uid)'),
			strpos($shareAdmission, '$identity($fresh) !== $identity($share)'));
		self::assertLessThan(strpos($shareAdmission, '$maskStorage = $storage->getWrapperStorage()'),
			strpos($shareAdmission, 'self::initializeLinkedSharedStorage($storage, $fresh, $uid)'));

		$start = strpos($source, 'private static function initializeLinkedSharedStorage(');
		$end = strpos($source, 'private static function configuration()', $start);
		self::assertIsInt($start);
		self::assertIsInt($end);
		$initializer = substr($source, $start, $end - $start);
		self::assertStringContainsString('new FinalShareHomeRoot($ownerUid, $home)', $initializer);
		self::assertStringContainsString('$rootProperty->setValue($storage, $boundedRoot)', $initializer);
		self::assertStringContainsString('$rootProperty->setValue($storage, $root)', $initializer);
		self::assertStringContainsString('finally {', $initializer);
		self::assertStringContainsString('$root->get($homePath)', $initializer);
		self::assertStringNotContainsString('getUserFolder(', $initializer);

		$start = strpos($source, 'public static function linkedSharedAudience(');
		$end = strpos($source, 'public static function path(', $start);
		self::assertIsInt($start);
		self::assertIsInt($end);
		$audience = substr($source, $start, $end - $start);
		self::assertStringContainsString("['OC\\Files\\Node\\Folder','OC\\Files\\Node\\LazyUserFolder']", $audience);
		self::assertStringContainsString('$realHome->get($relative)', $audience);
		self::assertStringNotContainsString('$node->getParent()', $audience);
	}
}
