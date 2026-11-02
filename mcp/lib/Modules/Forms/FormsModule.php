<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

final class FormsModule implements \OCA\Mcp\Mcp\Tools\ToolModule {
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories) {
        $expected = array_keys(FormsToolCatalog::definitions());
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
        return array_values(FormsToolCatalog::definitions());
    }

    public function call(string $name, array $arguments, string $userId): array {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $tool = ($this->factories[$name])();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Factory returned a different tool');
        }

        return $tool->execute($arguments, new ToolContext($userId));
    }

    public static function withReader(FormsReader $reader): self {
        return new self([
            'forms_list' => static fn (): Tool => new Tools\ListForms($reader),
            'forms_read' => static fn (): Tool => new Tools\ReadForm($reader),
        ]);
    }
}
