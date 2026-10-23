<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

use OCA\Mcp\Modules\Deck\Access\DeckAvailability;
use OCA\Mcp\Modules\Deck\Execution\ErrorMessages;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Deck\Presentation\DeckGuide;
use OCA\Mcp\Modules\Deck\Presentation\DeckPlanRenderer;
use OCA\Mcp\Mcp\Confirmation\PreviewsWrites;
use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;

final class DeckModule implements ToolModule, PreviewsWrites, RendersPlans, ToolGuideNotes {
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(
        private array $factories,
        private DeckAvailability $availability,
        private ?\OCA\Mcp\Modules\Deck\Access\DeckCapabilities $capabilities = null,
    ) {
        $expected = array_keys(DeckToolCatalog::definitions());
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Deck factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Deck factories must be closures');
            }
        }
    }

    public function definitions(): array {
        return array_values(array_filter(
            DeckToolCatalog::definitions(),
            fn(array $d): bool => !$this->isDependency($d['name']) || $this->dependenciesAvailable(),
        ));
    }

    public function guideNotes(): array {
        return array_merge(DeckGuide::notes(), ['Use deck_list_labels before incremental add/remove label edits; repeat plan_state after approval. Unlinking a visible native file association can remove another editor’s association and the access/mount it provides, including your last mount. The file itself remains; recovery may require the native Deck UI.'], $this->dependenciesAvailable() ? [\OCA\Mcp\Modules\Deck\Presentation\CapabilityMessages::TOOL_READ_DEPENDENCIES_DESCRIPTION] : []);
    }

    public function call(
        string $name,
        array $arguments,
        string $userId,
    ): array {
        $this->assertAvailable($name, $userId);
        return $this->resolve($name)->execute($arguments, new ToolContext($userId));
    }

    public function preview(
        string $name,
        array $arguments,
        string $userId,
    ): array {
        if (!isset($this->factories[$name]) || DeckToolCatalog::definitions()[$name]['operation'] === 'read') {
            throw new \InvalidArgumentException(ErrorMessages::errorUnknownTool());
        }
        $this->assertAvailable($name, $userId);
        $tool = $this->resolve($name);
        if (!$tool instanceof WriteTool) {
            throw new \InvalidArgumentException('Tool has no write preview');
        }
        return $tool->preview($arguments, new ToolContext($userId));
    }

    public function renderPlan(string $tool, array $plan): ?string {
        return (new DeckPlanRenderer())->render($tool, $plan);
    }

    private function assertAvailable(string $name, string $userId): void {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException(ErrorMessages::errorUnknownTool());
        }
        $this->availability->assertFor($userId);
        if ($this->isDependency($name) && !$this->dependenciesAvailable()) {
            throw new \InvalidArgumentException(\OCA\Mcp\Modules\Deck\Presentation\CapabilityMessages::dependenciesUnavailable());
        }
    }

    private function dependenciesAvailable(): bool {
        // Missing capability information fails closed.
        return ($this->capabilities ?? null)?->dependencies() ?? false;
    }

    private function isDependency(string $name): bool {
        return in_array($name, ['deck_read_card_dependencies','deck_add_card_dependency','deck_remove_card_dependency'], true);
    }

    private function resolve(string $name): Tool {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $tool = $this->factories[$name]();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Deck factory resolved a different tool');
        }
        $definition = DeckToolCatalog::definitions()[$name];
        if ($definition['operation'] !== 'read' && !$tool instanceof WriteTool) {
            throw new \LogicException('Writing tools must implement WriteTool');
        }
        return $tool;
    }
}
