<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Execution;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
abstract class FormsWritingTool extends FormsCommandTool implements WriteTool {
    public function preview(array $arguments,ToolContext $context):array {return $this->coordinator->preview(static::NAME,$context->userId,$arguments);}
}
