<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\People\PeopleModule;
use OCA\Mcp\Modules\People\PeopleToolCatalog;
use OCA\Mcp\Modules\People\Tools\SearchUsers;
use OCA\Mcp\Modules\People\Tools\ResolveUsers;
use OCA\Mcp\Modules\People\Tools\ListTeams;
use OCA\Mcp\Modules\People\Tools\ListTeamMembers;
use PHPUnit\Framework\TestCase;
use OCA\Mcp\Tests\Support\Mcp\AssertsReadablePlans;
use OCA\Mcp\Modules\People\Tools\FilterTeamMembers;
use OCA\Mcp\Modules\People\Tools\AddTeamMember;
use OCA\Mcp\Modules\People\Tools\RemoveTeamMember;

final class PeopleCatalogTest extends TestCase {
    use AssertsReadablePlans;
    protected function setUp(): void {
        self::assertTrue(class_exists(PeopleModule::class), 'Final People publisher is missing');
    }

    private function wrongTool(): Tool {
        return new class implements Tool {
            public static function definition(): ToolDefinition {
                return new ToolDefinition('wrong_tool', 'people', 'write', 'Invalid fixture', []);
            }
            public function execute(array $arguments, ToolContext $context): array { throw new \LogicException('Must not execute'); }
        };
    }

    /** @return array<string, \Closure():Tool> */
    private function factories(bool $circlesAvailable = false): array {
        $factories = [];
        foreach (PeopleToolCatalog::classes($circlesAvailable) as $class) {
            $definition = $class::definition();
            $factories[$definition->name] = static fn (): Tool => throw new \LogicException('Must not resolve');
        }
        return $factories;
    }

    public function testPublicationIsLazyAndReadOnly(): void {
        $module = new PeopleModule($this->factories());
        self::assertSame(['users_search', 'users_resolve'], array_column($module->definitions(), 'name'));
        self::assertSame([SearchUsers::class, ResolveUsers::class], PeopleToolCatalog::classes());
        self::assertCount(4, $module->guideNotes());
        self::assertFalse(is_a(SearchUsers::class, \OCA\Mcp\Mcp\Tools\WriteTool::class, true));
    }

    public function testCirclesToolsArePublishedOnlyWhenRequestedAndCarryOptionalAppMetadata(): void {
        $module = new PeopleModule($this->factories(true), true);
        self::assertSame(['users_search','users_resolve','users_list_teams','users_list_team_members','users_filter_team_members','users_add_team_member','users_remove_team_member'], array_column($module->definitions(), 'name'));
        self::assertSame([SearchUsers::class,ResolveUsers::class,ListTeams::class,ListTeamMembers::class,FilterTeamMembers::class,AddTeamMember::class,RemoveTeamMember::class], PeopleToolCatalog::classes(true));
        self::assertArrayNotHasKey('app', $module->definitions()[0]);
        self::assertSame('circles', $module->definitions()[2]['app']);
        self::assertSame('circles', $module->definitions()[3]['app']);
    }

    public function testEachTeamMembershipChangeRendersAnInertPlan(): void {
        $module = new PeopleModule($this->factories(true), true);
        $common = [
            'team' => ['displayName' => 'Editors'],
            'member' => ['uid' => 'alice', 'displayName' => 'Alice'],
        ];
        $this->assertEveryWriteToolHasAReadablePlan($module, [
            'users_add_team_member' => $common + ['impact' => 'Access to team content can start.'],
            'users_remove_team_member' => $common + ['impact' => 'Access to team content can end.'],
        ]);
    }

    public function testInvalidFactoryMapsAreRejected(): void {
        foreach ([[], array_replace($this->factories(), ['users_search' => 'not a closure']), array_replace($this->factories(), ['extra' => static fn () => null])] as $factories) {
            try { new PeopleModule($factories); self::fail('Invalid map accepted'); }
            catch (\LogicException) { self::addToAssertionCount(1); }
        }
    }

    public function testUnknownNameDoesNotResolveTheFactory(): void {
        $module = new PeopleModule($this->factories());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool');
        $module->call('unknown', [], 'alice');
    }

    public function testMismatchedResultsNeverExecute(): void {
        foreach ([new \stdClass(), $this->wrongTool()] as $wrong) {
            $factories = $this->factories();
            $factories['users_search'] = static fn () => $wrong;
            $module = new PeopleModule($factories);
            try { $module->call('users_search', ['query' => 'ok'], 'alice'); self::fail('Mismatched tool accepted'); }
            catch (\LogicException $e) { self::assertSame('Factory returned a different tool', $e->getMessage()); }
        }
    }

    public function testCatalogRejectsDuplicatesNonToolsAndNonReads(): void {
        foreach ([[SearchUsers::class, SearchUsers::class], [\stdClass::class], [$this->wrongTool()::class]] as $classes) {
            try { PeopleToolCatalog::definitions($classes); self::fail('Invalid catalog accepted'); }
            catch (\LogicException) { self::addToAssertionCount(1); }
        }
    }
}
