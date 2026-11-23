<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\People\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\People\Circles\CirclesDirectory;

final class RemoveTeamMember implements WriteTool
{
    public function __construct(private CirclesDirectory $circles) {}

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition('users_remove_team_member','people','edit',
            'Remove one exact direct local member from a team you can manage under native Circles role rules. Preview the team, recipient, and possible access revocation first; repeat with confirm=true and the returned plan_state. The team owner and members at or above your role cannot be removed.',
            ['type'=>'object','properties'=>[
                'team_id'=>['type'=>'string','minLength'=>1,'maxLength'=>255,'description'=>'ID from users_list_teams.'],
                'user_id'=>['type'=>'string','minLength'=>1,'maxLength'=>255,'description'=>'Exact local Nextcloud user ID of a direct team member.'],
                PlanState::ARGUMENT=>PlanState::property(),
            ],'required'=>['team_id','user_id'],'additionalProperties'=>false],app:'circles',destructiveHint:true);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        if (!WriteGate::confirmed($arguments)) { return ToolResult::json($this->preview($arguments,$context)); }
        return ToolResult::json($this->circles->removeMember($arguments,$context->userId,self::definition()->name));
    }

    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->circles->previewRemoveMember($arguments,$context->userId,self::definition()->name);
    }
}
