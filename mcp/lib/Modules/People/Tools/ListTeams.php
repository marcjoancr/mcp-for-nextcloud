<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\People\Tools;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\People\Circles\CirclesDirectory;
final class ListTeams implements Tool {
    public function __construct(private CirclesDirectory $circles) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition(name:'users_list_teams',module:'people',operation:'read',app:'circles',
            description:'Lists visible Nextcloud Teams (Circles) that the signed-in account belongs to. Hidden teams are excluded; search is limited to the actor’s native memberships.',
            inputSchema:['type'=>'object','properties'=>[
                'query'=>['type'=>'string','minLength'=>0,'maxLength'=>100,'default'=>'','description'=>'Optional name fragment (2 to 100 characters).'],
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>CirclesDirectory::MAX_TEAMS,'default'=>25,'description'=>'Maximum number of teams to return (default 25).'],
            ],'additionalProperties'=>false]);
    }
    public function execute(array $arguments, ToolContext $context): array {
        return ToolResult::json($this->circles->listTeams($context->userId,(string)($arguments['query']??''),(int)($arguments['limit']??25)));
    }
}
