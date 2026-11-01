<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Sharing;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Sharing\ShareAccess;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

final class ShareAccessConnectionTest extends TestCase {
    public function testPublicLinkNeedsConsentForTheActualToolAndAction(): void {
        $policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
        $policy->setGrant('alice', 'files', 'share', true);
        $policy->setGrant('alice', 'files', 'link', true);
        $context = new ConnectionContext();
        $context->setSelection(['files_share', 'files_unshare'], ['files_share' => ['share'], 'files_unshare' => ['link']]);
        $access = new ShareAccess($this->createStub(IManager::class), $policy, $this->createStub(FilesAccessGuard::class), $context);
        $access->assertGranted('alice', IShare::TYPE_GROUP, 'files_share');
        $access->assertGranted('alice', IShare::TYPE_LINK, 'files_unshare');
        $this->expectException(ToolFailure::class);
        $access->assertGranted('alice', IShare::TYPE_LINK, 'files_share');
    }
}
