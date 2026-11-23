<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
use OCA\Mcp\Mcp\Confirmation\PreviewsWrites;
use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;

/** Static publication and lazy dispatch of account and team tools. */
final class PeopleModule implements ToolModule, ToolGuideNotes, PreviewsWrites, RendersPlans {
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories, private bool $circlesAvailable = false) {
        $expected = array_keys(PeopleToolCatalog::definitions(circlesAvailable: $circlesAvailable));
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Factories must be closures');
            }
        }
    }

    public function definitions(): array {
        return array_values(PeopleToolCatalog::definitions(circlesAvailable: $this->circlesAvailable));
    }

    public function call(string $name, array $arguments, string $userId): array {
        return $this->resolve($name)->execute($arguments, new ToolContext($userId));
    }

    public function preview(string $name, array $arguments, string $userId): array {
        $tool = $this->resolve($name);
        if (!$tool instanceof WriteTool) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        return $tool->preview($arguments, new ToolContext($userId));
    }

    private function resolve(string $name): Tool {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $tool = $this->factories[$name]();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Factory returned a different tool');
        }
        return $tool;
    }

    public function renderPlan(string $tool, array $plan): ?string {
        if (!in_array($tool, ['users_add_team_member', 'users_remove_team_member'], true)
            || !isset($plan['member']['uid'], $plan['team']['displayName'], $plan['impact'])) {
            return null;
        }
        $member = $plan['member'];
        $values = [
            PlanText::inline((string)$member['uid']),
            PlanText::inline((string)$plan['team']['displayName']),
        ];
        $change = $tool === 'users_add_team_member'
            ? Translator::t('Add %1$s to the team %2$s.', $values)
            : Translator::t('Remove %1$s from the team %2$s.', $values);
        return $change . "\n\n" . PlanText::inline((string)$plan['impact']);
    }

    /** @return list<string> short behaviour notes about this module */
    public function guideNotes(): array {
        return [
            'Use users_resolve for batches of names, account IDs or email addresses. It returns exact matches and suggestions; confirm ambiguous matches before acting. ',
            'Before acting on a person (inviting, mentioning, messaging), when the user did not give the account ID, '
                . 'call users_search and confirm with the user when more than one account matches. Never invent an ID.',
            'users_search and users_resolve follow the sharing settings of the administrator: they may return nobody for a partial term.',
            ...($this->circlesAvailable ? ['users_list_teams and users_list_team_members are available only when Circles is enabled; membership reads use the signed-in actor and omit hidden teams. Filter visible professional profiles with users_filter_team_members. Review the team, account and access impact before confirming a membership change.'] : []),
            'To share something with a whole group, call users_search with include_groups: true and take the ID of the '
                . 'row whose type is "group": a share takes it as group:<id>. Groups are not searched without that '
                . 'argument, and the same administrator restrictions of accounts apply to them.',
        ];
    }

}
