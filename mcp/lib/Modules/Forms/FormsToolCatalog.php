<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

use OCA\Mcp\Mcp\Tools\Tool;

final class FormsToolCatalog {
    /** @return list<class-string<Tool>> */
    public static function classes(): array {
        return [Tools\ListForms::class, Tools\ReadForm::class, Tools\ReadResponses::class,
            Tools\SummarizeResponses::class, Tools\ExportResponses::class];
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array {
        $result = [];
        foreach (self::classes() as $class) {
            if (!is_a($class, Tool::class, true)) {
                throw new \LogicException('Catalog entries must implement Tool');
            }
            $definition = $class::definition();
            if (isset($result[$definition->name])) {
                throw new \LogicException('Duplicate tool: ' . $definition->name);
            }
            $result[$definition->name] = $definition->toArray();
        }

        return $result;
    }
}
