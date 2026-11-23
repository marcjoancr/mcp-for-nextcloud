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
use OCA\Mcp\Modules\Calendar\Trash\CalendarTrashReader;

final class ListTrashedEvents implements Tool
{
    public const NAME = 'calendar_list_trashed_events';
    public function __construct(private CalendarTrashReader $operation) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(self::NAME,'calendar','read','List recoverable events in your native calendar trash. Only calendars in your current calendar scope are included.',[
            'type'=>'object','properties'=>(object)['calendar'=>DavCollectionSchema::calendar('Optional visible calendar path.'),'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>50]],
            'additionalProperties'=>false,
        ],app:'calendar');
    }
    public function execute(array $arguments, ToolContext $context): array { return $this->operation->execute($arguments,$context->userId); }
}
