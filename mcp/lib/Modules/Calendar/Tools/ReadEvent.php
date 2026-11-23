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
final class ReadEvent implements Tool
{
    public const NAME = 'calendar_read_event';
    public function __construct(private \OCA\Mcp\Modules\Calendar\Reading\EventDetailReader $operation) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name:self::NAME,module:'calendar',operation:'read',description:'Reads a visible event and its participation details.',inputSchema:[
            'type'=>'object','properties'=>(object)['calendar'=>DavCollectionSchema::calendar('visible calendar path'),'uid'=>DavCollectionSchema::text('event UID',1,255),'recurrence_id'=>DavCollectionSchema::text('original occurrence start as ISO date or timestamp',10,40)],'required'=>['calendar','uid'],'additionalProperties'=>false],app:'calendar');
    }
    public function execute(array $arguments, ToolContext $context): array { return $this->operation->execute($arguments,$context->userId); }
}
