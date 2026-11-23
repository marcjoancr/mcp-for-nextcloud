<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\People\Tools\SearchUsers;
use OCA\Mcp\Modules\People\Tools\ResolveUsers;
use OCA\Mcp\Modules\People\Tools\ListTeams;
use OCA\Mcp\Modules\People\Tools\ListTeamMembers;

final class PeopleToolCatalog {
    /** @return list<class-string<Tool>> */
    public static function classes(bool $circlesAvailable = false): array {
        $classes = [SearchUsers::class, ResolveUsers::class];
        if ($circlesAvailable) {
            $classes[] = ListTeams::class;
            $classes[] = ListTeamMembers::class;
            $classes[] = Tools\FilterTeamMembers::class;
            $classes[] = Tools\AddTeamMember::class;
            $classes[] = Tools\RemoveTeamMember::class;
        }
        return $classes;
    }

    /**
     * @param list<class-string<Tool>>|null $classes explicit entries, defaulting to this feature's catalog
     * @return array<string, array<string, mixed>> original metadata shape in publication order
     */
    public static function definitions(?array $classes = null, bool $circlesAvailable = false): array {
        $result = [];
        foreach ($classes ?? self::classes($circlesAvailable) as $class) {
            if (!is_a($class, Tool::class, true)) {
                throw new \LogicException('Catalog entries must implement Tool');
            }
            $definition = $class::definition();
            if ($definition->operation !== 'read' && ($definition->operation !== 'edit' || !is_a($class, WriteTool::class, true))) {
                throw new \LogicException('People writes must implement WriteTool and use the edit operation');
            }
            if (isset($result[$definition->name])) {
                throw new \LogicException('Duplicate tool: ' . $definition->name);
            }
            $result[$definition->name] = [
                'name' => $definition->name,
                'description' => $definition->description,
                'module' => $definition->module,
                'operation' => $definition->operation,
                ...($definition->app === null ? [] : ['app' => $definition->app]),
                'inputSchema' => $definition->inputSchema,
                ...($definition->destructiveHint === null ? [] : ['destructiveHint' => $definition->destructiveHint]),
            ];
        }
        return $result;
    }
}
