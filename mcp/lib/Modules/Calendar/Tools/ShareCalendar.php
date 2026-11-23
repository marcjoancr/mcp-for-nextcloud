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
use OCA\Mcp\Modules\Calendar\Collections\CalendarShareManager;

final class ShareCalendar implements WriteTool
{
    public const NAME = 'calendar_share_calendar';
    public function __construct(private CalendarShareManager $operation) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(self::NAME,'calendar','share','Share one of your own calendars with a local Nextcloud user, change read/write access, or remove a direct share. Review the plan and repeat with its plan_state to confirm.',[
            'type'=>'object','properties'=>(object)[
                'calendar'=>DavCollectionSchema::calendar('Owned calendar path.'),
                'shareWith'=>DavCollectionSchema::text('Exact local Nextcloud user ID.',1,255),
                'access'=>['type'=>'string','enum'=>['read','write','remove']],
                PlanState::ARGUMENT=>PlanState::property(),
            ],'required'=>['calendar','shareWith','access'],'additionalProperties'=>false,
        ],app:'calendar', destructiveHint:true);
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        if (!WriteGate::confirmed($arguments)) return ToolResult::json($this->preview($arguments,$context));
        return $this->operation->share($arguments,$context->userId,self::NAME);
    }
    public function preview(array $arguments, ToolContext $context): array { return $this->operation->preview($arguments,$context->userId,self::NAME); }
}
