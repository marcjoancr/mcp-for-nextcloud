<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability;
use OCA\Mcp\Modules\Files\FilesToolCatalog;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCP\App\IAppManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class FilesCapabilityIsolationTest extends TestCase {
    public const TOOLS = [
        'files_get_image_description' => 'description_read',
        'files_set_image_description' => 'description_edit',
        'files_list_tags' => 'tags_read',
        'files_create_tag' => 'tags_create',
        'files_set_tags' => 'tags_edit',
        'files_create_upload_link' => 'upload_link',
    ];

    public function testNewCapabilitiesRequireExplicitUserAndConnectionAssignments(): void {
        $config = (new InMemoryConfig())->mock($this);
        $policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
        $db = InMemoryConfig::groupDatabase($config)->connection();
        $mapper = new ToolGrantsMapper($db);
        $connection = new ConnectionContext();
        $apps = $this->createStub(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): \OCP\IUser {
            $user = $this->createStub(\OCP\IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        $availability = new ToolAvailability($policy, $apps, $users, null, $connection);
        $catalog = FilesToolCatalog::definitions();
        foreach (['read', 'edit', 'create', 'share', 'link'] as $op) {
            $policy->setGrant('alice', 'files', $op, true);
        }
        foreach (self::TOOLS as $name => $op) {
            self::assertArrayHasKey($name, $catalog);
            $definition = $catalog[$name];
            self::assertSame($op, $definition['operation']);
            self::assertFalse($availability->administrativelyAllowed($definition, 'alice'));
            $db->beginTransaction();
            $mapper->set(ToolGrantsMapper::USER, 'alice', $name, ToolGrantsMapper::ALLOW);
            $db->commit();
            self::assertTrue($availability->administrativelyAllowed($definition, 'alice'));
            self::assertFalse($availability->administrativelyAllowed($definition, 'bob'));
            $connection->set(10);
            $connection->setSelection([$name]);
            self::assertTrue($availability->allowed($definition, 'alice'));
            self::assertFalse($availability->allowed($definition, 'bob'));
            $connection->set(11);
            $connection->setSelection(['files_read']);
            self::assertFalse($availability->allowed($definition, 'alice'));
            $connection->set(12);
            self::assertFalse($availability->allowed($definition, 'alice'));
        }
        // A connection that can revoke read links must not inherit upload-link revocation.
        $unshare = $catalog['files_unshare'];
        $db->beginTransaction();
        $mapper->set(ToolGrantsMapper::USER, 'alice', 'files_unshare', ToolGrantsMapper::ALLOW);
        $db->commit();
        $connection->set(20);
        $connection->setSelection(['files_unshare'], ['files_unshare' => ['upload_link']]);
        self::assertSame([], $availability->permittedVariants($unshare, 'alice', true));
        $policy->setGrant('alice', 'files', 'upload_link', true);
        self::assertSame(['upload_link'], $availability->permittedVariants($unshare, 'alice', true));
        self::assertSame([], $availability->permittedVariants($unshare, 'bob', true));
        $connection->set(21);
        $connection->setSelection(['files_unshare'], ['files_unshare' => ['link']]);
        self::assertSame(['link'], $availability->permittedVariants($unshare, 'alice', true));
        $connection->set(22);
        $connection->setSelection(['files_unshare']);
        self::assertSame([], $availability->permittedVariants($unshare, 'alice', true));
    }
}
