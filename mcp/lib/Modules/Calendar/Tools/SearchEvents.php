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
final class SearchEvents implements Tool
{
    public const NAME = 'calendar_search_events';
    public function __construct(private \OCA\Mcp\Modules\Calendar\Reading\EventSearch $operation) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name:self::NAME, module:'calendar', operation:'read', description:'Searches visible event summaries, locations and descriptions in a bounded date range.', inputSchema:[
            'type'=>'object','properties'=>(object)['from'=>DavCollectionSchema::text('inclusive ISO date or timestamp',10,40),'to'=>DavCollectionSchema::text('exclusive ISO date or timestamp',10,40),'query'=>DavCollectionSchema::text('text to find',0,200),'participation_status'=>['type'=>'string','enum'=>['needs-action','accepted','declined','tentative']],'calendar'=>DavCollectionSchema::calendar('optional visible calendar path'),'offset'=>['type'=>'integer','minimum'=>0,'maximum'=>10000,'default'=>0],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>20]],'required'=>['from','to'],'additionalProperties'=>false],app:'calendar');
    }
    public function execute(array $arguments, ToolContext $context): array { return $this->operation->execute($arguments, $context->userId); }
}
