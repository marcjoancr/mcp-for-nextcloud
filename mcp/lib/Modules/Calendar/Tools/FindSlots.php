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
final class FindSlots implements Tool
{
    public const NAME = 'calendar_find_slots';
    public function __construct(private \OCA\Mcp\Modules\Calendar\Scheduling\SlotFinder $operation) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name:self::NAME,module:'calendar',operation:'availability',description:'Suggests work-hour meeting slots using the organizer calendar and native attendee free/busy checks.',inputSchema:[
            'type'=>'object','properties'=>(object)['from'=>DavCollectionSchema::text('inclusive ISO timestamp',20,40),'to'=>DavCollectionSchema::text('exclusive ISO timestamp',20,40),'duration_minutes'=>['type'=>'integer','minimum'=>15,'maximum'=>480,'default'=>30],
            'time_zone'=>DavCollectionSchema::text('IANA time zone',1,100),'work_start'=>['type'=>'string','pattern'=>'^(?:[01][0-9]|2[0-3]):[0-5][0-9]$','default'=>'09:00'],'work_end'=>['type'=>'string','pattern'=>'^(?:[01][0-9]|2[0-3]):[0-5][0-9]$','default'=>'17:00'],
            'workdays'=>['type'=>'array','minItems'=>1,'maxItems'=>7,'items'=>['type'=>'integer','minimum'=>1,'maximum'=>7],'default'=>[1,2,3,4,5]],'required_attendees'=>['type'=>'array','maxItems'=>50,'items'=>['type'=>'string','minLength'=>1]],'optional_attendees'=>['type'=>'array','maxItems'=>50,'items'=>['type'=>'string','minLength'=>1]],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>20,'default'=>5]],
            'required'=>['from','to'],'additionalProperties'=>false],app:'calendar');
    }
    public function execute(array $arguments, ToolContext $context): array { return $this->operation->execute($arguments,$context->userId); }
}
