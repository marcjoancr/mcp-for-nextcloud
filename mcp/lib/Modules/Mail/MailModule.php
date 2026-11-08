<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Confirmation\PreviewsWrites;
use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\ToolModule;

/** Static publication and lazy dispatch for the Mail feature. */
final class MailModule implements ToolModule, ToolGuideNotes, PreviewsWrites, RendersPlans {
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories, private ?\OCA\Mcp\Mcp\Operations\OperationJournal $operations = null) {
        $expected = array_keys(MailToolCatalog::definitions());
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
        return array_values(MailToolCatalog::definitions());
    }

    public function call(string $name, array $arguments, string $userId): array {
        $tool = $this->resolve($name);
        if ($this->operations === null || !WriteGate::isWrite(MailToolCatalog::definitions()[$name])) {
            return $tool->execute($arguments, new ToolContext($userId));
        }
        try {
            $data = $this->operations->execute($name, $userId, $arguments, static function () use ($tool, $arguments, $userId): array {
                $result = $tool->execute($arguments, new ToolContext($userId));
                if (($result['isError'] ?? false) === true) throw new \RuntimeException('Mail write did not confirm its result.');
                return $result['structuredContent'] ?? json_decode($result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
            });
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($data);
        } catch (\OCA\Mcp\Mcp\Confirmation\PlanChanged $e) {
            throw new \OCA\Mcp\Mcp\Confirmation\PlanChanged($this->operations->plan($name, $userId, $arguments, $e->plan));
        }
    }

    public function preview(string $name, array $arguments, string $userId): array {
        $tool = $this->resolve($name);
        if (!$tool instanceof WriteTool || !WriteGate::isWrite(MailToolCatalog::definitions()[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $plan = $tool->preview($arguments, new ToolContext($userId));
        return $this->operations?->plan($name, $userId, $arguments, $plan) ?? $plan;
    }

    public function renderPlan(string $tool, array $plan): ?string {
        return (new Presentation\MailPlanRenderer())->render($tool, $plan);
    }

    /** @return list<string> short behaviour notes about this module */
    public function guideNotes(): array {
        return Presentation\MailMessages::GUIDE_NOTES;
    }

    private function resolve(string $name): Tool {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $tool = $this->factories[$name]();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Factory returned a different tool');
        }
        if (WriteGate::isWrite(MailToolCatalog::definitions()[$name]) && !$tool instanceof WriteTool) {
            throw new \LogicException('Mail writes must implement WriteTool');
        }
        return $tool;
    }
}
