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
use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Calendar\Collections\CalendarCreator;

final class CalendarCreate implements WriteTool
{
    public const NAME = 'calendar_create_calendar';
    public function __construct(private CalendarCreator $operation) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(self::NAME, 'calendar', 'create', 'Create a personal calendar through native CalDAV. Review the plan, then repeat with its plan_state to confirm.', [
            'type'=>'object', 'properties'=>(object)['displayName'=>DavCollectionSchema::text('Calendar display name',1,255), PlanState::ARGUMENT=>PlanState::property()],
            'required'=>['displayName'], 'additionalProperties'=>false,
        ], app:'calendar');
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        if (!WriteGate::confirmed($arguments)) return ToolResult::json($this->preview($arguments,$context));
        return $this->operation->create($arguments,$context->userId,self::NAME);
    }
    public function preview(array $arguments, ToolContext $context): array { return $this->operation->preview($arguments,$context->userId,self::NAME); }
}
