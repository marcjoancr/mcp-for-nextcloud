<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Execution;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
abstract class FormsCommandTool implements Tool {
    protected const NAME='';
    public function __construct(protected FormsWriteCoordinator $coordinator) {}
    public static function definition():ToolDefinition {
        $d=FormsCommands::definitions()[static::NAME];
        return new ToolDefinition(name:$d['name'],module:'forms',operation:$d['operation'],description:$d['description'],
            inputSchema:$d['inputSchema'],app:'forms',grantAnyOf:$d['grantAnyOf']??null,
            destructiveHint:!FormsCommands::readOnly(static::NAME),grantAllOf:$d['grantAllOf']??null,
            requiresAdmin:in_array(static::NAME,['forms_read_admin_settings','forms_update_admin_settings'],true));
    }
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json($this->coordinator->call(static::NAME,$context->userId,$arguments));}
}
