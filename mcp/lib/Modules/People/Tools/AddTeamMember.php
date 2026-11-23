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

final class AddTeamMember implements WriteTool
{
    public function __construct(private CirclesDirectory $circles) {}

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition('users_add_team_member','people','edit',
            'Add one exact local Nextcloud account to a team you can manage under the native Circles role and membership rules. Preview the team, recipient, and access impact first; repeat with confirm=true and the returned plan_state. This adds the default member role only and never changes roles or ownership.',
            ['type'=>'object','properties'=>[
                'team_id'=>['type'=>'string','minLength'=>1,'maxLength'=>255,'description'=>'ID from users_list_teams.'],
                'user_id'=>['type'=>'string','minLength'=>1,'maxLength'=>255,'description'=>'Exact local Nextcloud user ID.'],
                PlanState::ARGUMENT=>PlanState::property(),
            ],'required'=>['team_id','user_id'],'additionalProperties'=>false],app:'circles',destructiveHint:false);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        if (!WriteGate::confirmed($arguments)) { return ToolResult::json($this->preview($arguments,$context)); }
        return ToolResult::json($this->circles->addMember($arguments,$context->userId,self::definition()->name));
    }

    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->circles->previewAddMember($arguments,$context->userId,self::definition()->name);
    }
}
