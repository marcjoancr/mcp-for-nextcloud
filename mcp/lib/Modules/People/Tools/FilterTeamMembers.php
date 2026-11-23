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

final class FilterTeamMembers implements Tool
{
    public function __construct(private CirclesDirectory $circles) {}

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'users_filter_team_members',
            module: 'people',
            operation: 'read',
            description: 'Filters visible members of one of your Nextcloud Teams by organisation and professional role. Only profile values visible to your account are used or returned. The team membership role is reported separately from the person’s professional role; large scans report truncation.',
            inputSchema: ['type'=>'object','properties'=>[
                'team_id'=>['type'=>'string','minLength'=>1,'maxLength'=>255,'description'=>'ID from users_list_teams.'],
                'organisation'=>['type'=>'string','minLength'=>2,'maxLength'=>100,'description'=>'Optional case-insensitive organisation fragment from visible profile fields.'],
                'professional_role'=>['type'=>'string','minLength'=>2,'maxLength'=>100,'description'=>'Optional case-insensitive professional-role fragment from visible profile fields.'],
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>CirclesDirectory::MAX_MEMBERS,'default'=>50],
            ],'required'=>['team_id'],'additionalProperties'=>false],
            app: 'circles',
        );
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return ToolResult::json($this->circles->filterMembers($context->userId,(string)$arguments['team_id'],(string)($arguments['organisation']??''),(string)($arguments['professional_role']??''),(int)($arguments['limit']??50)));
    }
}
