<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Access\MailAccessGuard;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use PHPUnit\Framework\TestCase;
final class MailHistoricalScopeTest extends TestCase {
    public function testHistoricalOriginalSourceAndDestinationBothRequireCurrentScopeWithoutLiveLookup():void {
        $scope=(new ScopeStub())->restrict('mail',['mailbox:7','mailbox:8']);$g=new MailAccessGuard($scope);
        $refs=['v'=>1,'mail'=>[['account'=>3,'mailbox'=>7],['account'=>3,'mailbox'=>8]],'global'=>false];
        $g->authorizeReferences('alice',$refs);self::assertTrue(true);
        $scope->restrict('mail',['mailbox:7']);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$g->authorizeReferences('alice',$refs);
    }
    public function testHistoricalAccountGrantCannotBeReplacedByFolderGrant():void {
        $g=new MailAccessGuard((new ScopeStub())->restrict('mail',['mailbox:7']));
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $g->authorizeReferences('alice',['v'=>1,'mail'=>[['account'=>3,'mailbox'=>0]],'global'=>false]);
    }
}
