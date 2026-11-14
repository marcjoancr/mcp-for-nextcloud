<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Access;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\AppAvailability\AppEnablement;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkAuthority;
use OCA\Mcp\OAuth\ConnectionToolsStore;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\App\IAppManager;
/** Deferred validation reads persisted policy directly, without borrowing the worker's session/connection. */
final class FormsDeferredAccess {
    public function __construct(private GrantPolicy $policy,private IAppManager $apps,private IUserManager $users,
        private GuestRestrictions $guests,private ConnectionToolsStore $selections,private IGroupManager $groups,private FormsPrincipal $principal) {}
    public function assert(array $origin):void {
        $uid=$origin['uid'];$connection=$origin['connection'];
        $this->policy->freshWithin($uid,function(GrantPolicy $fresh)use($uid,$connection,$origin):void {
            $user=$this->users->get($uid);
            if($user===null||!$user->isEnabled()||!$this->apps->isEnabledForUser('mcp',$user)||!AppEnablement::forUser($this->apps,'forms',$user)||!$fresh->canConnect($uid))throw new ToolFailure(ToolFailure::FORBIDDEN);
            $selection=$this->selections->get($uid,$connection);
            if($selection===null)throw new ToolFailure(ToolFailure::FORBIDDEN);
            $requests=[[$origin['tool'],$origin['operations'],$origin['references']['files_operations']??[]]];
            if(isset($origin['references']['bulk_authority'])) {
                $authority=FormsBulkAuthority::checked($origin['references']['bulk_authority']);
                $requests[]=[$authority['tool'],$authority['required_operations'],$authority['files_operations']];
            }
            foreach($requests as [$tool,$operations,$files])$this->admit($fresh,$uid,$tool,$operations,$files,$selection);
        });
    }
    private function admit(GrantPolicy $fresh,string $uid,string $tool,array $operations,array $files,array $selection):void {
        $definition=FormsCommands::definitions()[$tool]??throw new ToolFailure(ToolFailure::FORBIDDEN);
        if(!in_array($tool,$selection['tools'],true))throw new ToolFailure(ToolFailure::FORBIDDEN);
        $this->principal->assert($uid,(bool)($definition['requiresAdmin']??false));
        $availabilityDefinition=$definition;unset($availabilityDefinition['requiresAdmin']);
        if(!((new ToolAvailability($fresh,$this->apps,$this->users,$this->guests,null,$this->groups))->allowed($availabilityDefinition,$uid)))throw new ToolFailure(ToolFailure::FORBIDDEN);
        foreach($operations as $operation) {
            if(!is_string($operation)||!in_array($operation,GrantPolicy::CATALOG['forms'],true)||!$fresh->granted($uid,'forms',$operation))throw new ToolFailure(ToolFailure::FORBIDDEN);
            if(isset($definition['grantAnyOf'])&&!in_array($operation,$selection['variants'][$tool]??[],true))throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        foreach($files as $operation) {
            if(!is_string($operation)||!in_array($operation,GrantPolicy::CATALOG['files'],true)||!$fresh->granted($uid,'files',$operation))throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
    }
}
