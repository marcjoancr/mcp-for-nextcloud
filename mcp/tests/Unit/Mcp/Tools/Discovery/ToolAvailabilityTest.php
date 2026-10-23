<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Discovery;

use OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability;
use OCA\Mcp\Tests\Support\Mcp\FakeRestrictedModule;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use PHPUnit\Framework\TestCase;
final class ToolAvailabilityTest extends TestCase {
    public function testEveryCardFileToolRequiresItsCrossModuleGrants(): void {
        $policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
        $availability = new ToolAvailability($policy, $this->createMock(\OCP\App\IAppManager::class), $this->createMock(\OCP\IUserManager::class));
        $policy->setGrant('alice', 'deck', 'read', true);
        $policy->setGrant('alice', 'deck', 'edit', true);
        $policy->setGrant('alice', 'files', 'read', false);
        $policy->setGrant('alice', 'files', 'share', false);
        $definitions = \OCA\Mcp\Modules\Deck\DeckToolCatalog::definitions();
        foreach (['deck_list_card_files', 'deck_unlink_card_file', 'deck_link_card_file'] as $name) {
            $definition = $definitions[$name]; unset($definition['app']);
            self::assertFalse($availability->allowed($definition, 'alice'), $name . ' must not advertise without Files READ');
        }
        $policy->setGrant('alice', 'files', 'read', true);
        foreach (['deck_list_card_files', 'deck_unlink_card_file'] as $name) {
            $definition = $definitions[$name]; unset($definition['app']);
            self::assertTrue($availability->allowed($definition, 'alice'));
        }
        $definition = $definitions['deck_link_card_file']; unset($definition['app']);
        self::assertFalse($availability->allowed($definition, 'alice'));
        $policy->setGrant('alice', 'files', 'share', true);
        self::assertTrue($availability->allowed($definition, 'alice'));
        $policy->setGrant('alice', 'deck', 'edit', false);
        self::assertFalse($availability->allowed($definition, 'alice'));
    }

    public function testRoleGrantAndAppChecksKeepTheirOrder(): void {
        $policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->expects(self::never())->method('isEnabledForUser');
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->expects(self::never())->method('get');
        $availability = new ToolAvailability($policy, $apps, $users);
        $module = new FakeRestrictedModule();
        self::assertFalse($availability->permits($module, 'alice'));
        self::assertTrue($availability->permits($module, 'root'));
        self::assertFalse($availability->allowed(['module' => 'logs', 'operation' => 'read', 'app' => 'notes'], 'root'));
        $policy->setGrant('root', 'files', 'link', true);
        self::assertTrue($availability->allowed(['module' => 'files', 'operation' => 'share', 'grantAnyOf' => ['share', 'link']], 'root'));
        $policy->setGrant('root', 'files', 'link', false);
        self::assertFalse($availability->allowed(['module' => 'files', 'operation' => 'share', 'grantAnyOf' => ['share', 'link']], 'root'));
    }
}
