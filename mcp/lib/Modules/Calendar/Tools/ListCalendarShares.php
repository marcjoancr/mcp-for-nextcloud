<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Calendar\Collections\CalendarShareManager;

final class ListCalendarShares implements Tool
{
    public const NAME = 'calendar_list_calendar_shares';
    public function __construct(private CalendarShareManager $operation) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(self::NAME,'calendar','read','List direct local-user shares on one of your own calendars.',[
            'type'=>'object','properties'=>(object)['calendar'=>DavCollectionSchema::calendar('Owned calendar path.')],
            'required'=>['calendar'],'additionalProperties'=>false,
        ],app:'calendar');
    }
    public function execute(array $arguments, ToolContext $context): array { return $this->operation->list($arguments,$context->userId); }
}
