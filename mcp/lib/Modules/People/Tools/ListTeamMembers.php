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
final class ListTeamMembers implements Tool {
    public function __construct(private CirclesDirectory $circles) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition(name:'users_list_team_members',module:'people',operation:'read',app:'circles',
            description:'Lists direct members of a visible Nextcloud Team (Circle), with their native member role and type. Only active local user members have a uid; nested groups, nested teams and remote or external members are labeled by type and are not expanded.',
            inputSchema:['type'=>'object','properties'=>[
                'team_id'=>['type'=>'string','minLength'=>1,'maxLength'=>255,'description'=>'ID from users_list_teams.'],
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>CirclesDirectory::MAX_MEMBERS,'default'=>50,'description'=>'Maximum number of direct members to return (default 50).'],
            ],'required'=>['team_id'],'additionalProperties'=>false]);
    }
    public function execute(array $arguments, ToolContext $context): array {
        return ToolResult::json($this->circles->listMembers($context->userId,(string)$arguments['team_id'],(int)($arguments['limit']??50)));
    }
}
