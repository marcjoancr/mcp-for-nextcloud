<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Modules\Calendar\Resources\CalendarResourceDirectory;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\DavCollectionSchema;

final class ListResources implements Tool
{
    public const NAME = 'calendar_list_resources';
    public function __construct(private CalendarResourceDirectory $directory) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name:self::NAME,module:'calendar',operation:'read',description:'Lists native CalDAV rooms and resources visible to the acting user. Search results follow the resource backend group restrictions.',inputSchema:[
            'type'=>'object','properties'=>(object)[
                'type'=>['type'=>'string','enum'=>['ALL','ROOM','RESOURCE'],'default'=>'ALL'],
                'query'=>DavCollectionSchema::text('optional display-name substring',0,128),
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50,'default'=>50],
            ],'additionalProperties'=>false],app:'calendar');
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        return \OCA\Mcp\Modules\Calendar\Presentation\CalendarResult::json([
            'resources'=>$this->directory->search($context->userId,$arguments['type']??'ALL',$arguments['query']??'',(int)($arguments['limit']??50)),
        ]);
    }
}
