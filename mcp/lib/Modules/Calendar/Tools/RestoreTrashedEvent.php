<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Calendar\Trash\CalendarTrashRestorer;

final class RestoreTrashedEvent implements WriteTool
{
    public const NAME = 'calendar_restore_trashed_event';
    public function __construct(private CalendarTrashRestorer $operation) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(self::NAME,'calendar','restore','Restore one event from your native calendar trash after reviewing its plan. Native restore does not send an invitation.',[
            'type'=>'object','properties'=>(object)['object_id'=>['type'=>'integer','minimum'=>1],PlanState::ARGUMENT=>PlanState::property()],
            'required'=>['object_id'],'additionalProperties'=>false,
        ],app:'calendar',destructiveHint:true);
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        if (!WriteGate::confirmed($arguments)) return ToolResult::json($this->preview($arguments,$context));
        return $this->operation->restore($arguments,$context->userId);
    }
    public function preview(array $arguments, ToolContext $context): array { return $this->operation->preview($arguments,$context->userId); }
}
