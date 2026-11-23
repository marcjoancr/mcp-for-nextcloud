<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Modules\Calendar\Resources\CalendarResourceDirectory;
use OCA\Mcp\Modules\Calendar\Resources\ResourceAvailability;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\DavCollectionSchema;

final class CheckResourceAvailability implements Tool
{
    public const NAME = 'calendar_check_resource_availability';
    public function __construct(private CalendarResourceDirectory $directory, private EventDates $dates, private ResourceAvailability $availability) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name:self::NAME,module:'calendar',operation:'availability',description:'Queries native CalDAV free/busy for a visible room or resource. Reports free or busy only when the server returns a verified result; otherwise availability is unknown.',inputSchema:[
            'type'=>'object','properties'=>(object)[
                'type'=>['type'=>'string','enum'=>['ROOM','RESOURCE']],
                'resource'=>DavCollectionSchema::text('ID returned by calendar_list_resources',1,512),
                'start'=>DavCollectionSchema::text('inclusive ISO timestamp or date',10,40),
                'end'=>DavCollectionSchema::text('exclusive ISO timestamp or date',10,40),
            ],'required'=>['type','resource','start','end'],'additionalProperties'=>false],app:'calendar');
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        $resource = $this->directory->resolve($context->userId,$arguments['type'],$arguments['resource']);
        $start=$this->dates->parse($arguments['start'],'start');
        $end=$this->dates->parse($arguments['end'],'end');
        if ($start >= $end) throw new CalendarArgumentException('end must be after start.','end','must be after start');
        if ($end->getTimestamp()-$start->getTimestamp()>31*86400) throw CalendarException::limit('The resource availability window must not exceed 31 days.');
        $status=$this->availability->status($context->userId,$resource['email'],$start,$end);
        return \OCA\Mcp\Modules\Calendar\Presentation\CalendarResult::json([
            'resource'=>['id'=>$resource['id'],'type'=>$resource['type'],'name'=>$resource['name']],
            'start'=>$start->format('Y-m-d\TH:i:s\Z'),'end'=>$end->format('Y-m-d\TH:i:s\Z'),
            'availability'=>$status,
        ]);
    }
}
