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
		self::assertStringContainsString('$manager->getShareById($canonicalId, $uid)', $shareAdmission);
		self::assertStringNotContainsString('$provider->getShareById(', $shareAdmission);
		self::assertStringContainsString('$fresh->getFullId() !== $canonicalId', $shareAdmission);
		self::assertStringContainsString('$fresh->getSharedWith() !== $uid', $shareAdmission);
		self::assertStringContainsString('$fresh->getShareOwner() !== $share->getShareOwner()', $shareAdmission);
		self::assertStringContainsString('$fresh->getNodeId() !== $share->getNodeId()', $shareAdmission);
		self::assertStringContainsString('$fresh->getPermissions() !== $permissions', $shareAdmission);
		self::assertStringContainsString('count($grouped) !== 1', $shareAdmission);
		self::assertStringContainsString('$component->getFullId() !== $canonicalId', $shareAdmission);
		self::assertStringContainsString('$component->getSharedWith() !== $uid', $shareAdmission);
		self::assertStringContainsString('$component->getPermissions() !== $fresh->getPermissions()', $shareAdmission);
		self::assertStringNotContainsString('$share->getFullId()', $shareAdmission);
		self::assertLessThan(strpos($shareAdmission, 'self::initializeLinkedSharedStorage($storage, $fresh, $uid)'),
			strpos($shareAdmission, '$fresh->getFullId() !== $canonicalId'));
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
		self::assertStringContainsString('$share=self::shared($shared,$uid,', $audience);
		self::assertStringContainsString('PERMISSION_READ|($create?\\OCP\\Constants::PERMISSION_CREATE:\\OCP\\Constants::PERMISSION_UPDATE)', $audience);
		self::assertStringContainsString('count($grouped)!==1', $audience);
		self::assertStringContainsString('$share->getSharedWith()!==$uid', $audience);
		self::assertStringNotContainsString('$syntheticShare->getSharedWith()', $audience);
		self::assertStringContainsString("['OC\\Files\\Node\\Folder','OC\\Files\\Node\\LazyUserFolder']", $audience);
		self::assertStringContainsString('$realHome->get($relative)', $audience);
		self::assertStringNotContainsString('$node->getParent()', $audience);
	}
}
