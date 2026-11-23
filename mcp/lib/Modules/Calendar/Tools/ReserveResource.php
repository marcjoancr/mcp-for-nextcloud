<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Modules\Calendar\Execution\EventApproval;
use OCA\Mcp\Modules\Calendar\Resources\ResourceReserver;
use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

final class ReserveResource implements \OCA\Mcp\Mcp\Tools\WriteTool
{
    public const NAME = 'calendar_reserve_resource';
    public function __construct(private ResourceReserver $operation, private EventApproval $approval) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name:self::NAME,module:'calendar',operation:'edit',description:'Requests a visible room or resource for an existing non-recurring event using native CalDAV scheduling. This sends a scheduling request; resource acceptance remains separate from the successful event update. First returns a plan without writing; show it and wait for explicit approval before repeating with confirm=true and the etag returned by the plan.',inputSchema:[
            'type'=>'object','properties'=>(object)[
                'calendar'=>DavCollectionSchema::calendar('calendar path returned by calendar_list_calendars'),
                'uid'=>DavCollectionSchema::uid(),
                'type'=>['type'=>'string','enum'=>['ROOM','RESOURCE']],
                'resource'=>DavCollectionSchema::text('ID returned by calendar_list_resources',1,512),
                'etag'=>DavCollectionSchema::etag(),
                'confirm_shared'=>['type'=>'boolean','description'=>'Acknowledge a shared calendar only after showing its owner notice.'],
            ],'required'=>['calendar','uid','type','resource'],'additionalProperties'=>false],app:'calendar',destructiveHint:true);
    }
    public function execute(array $arguments, ToolContext $context): array { return $this->approval->execute($this->operation,$arguments,$context->userId); }
    public function preview(array $arguments, ToolContext $context): array { return $this->approval->preview(self::NAME,$this->operation,$arguments,$context->userId); }
}
