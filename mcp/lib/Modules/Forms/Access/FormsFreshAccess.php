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
use OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\OAuth\ConnectionToolsStore;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\App\IAppManager;
/** Rebuild a coherent policy window at every projection and native effect boundary. */
final class FormsFreshAccess {
    public function __construct(private GrantPolicy $policy,private IAppManager $apps,private IUserManager $users,
        private GuestRestrictions $guests,private ConnectionContext $connection,private ConnectionToolsStore $selections,
        private IGroupManager $groups,private IUserSession $session,private FormsPrincipal $principal) {}
    public function assertTool(string $uid,string $tool,array $arguments,array $snapshot=[]):void {
        $this->policy->freshWithin($uid,function(GrantPolicy $fresh)use($uid,$tool,$arguments,$snapshot):void {
            $user=$this->users->get($uid);$connection=$this->connection->current();
            if($user===null||!$user->isEnabled()||$this->session->getUser()?->getUID()!==$uid
                ||!$this->apps->isEnabledForUser('mcp',$user)||!$fresh->canConnect($uid))throw new ToolFailure(ToolFailure::FORBIDDEN);
            $selection=$this->selections->get($uid,$connection);
            $definition=FormsCommands::definitions()[$tool]??throw new ToolFailure(ToolFailure::FORBIDDEN);
            if($selection===null||!in_array($tool,$selection['tools'],true))throw new ToolFailure(ToolFailure::FORBIDDEN);
            if($tool!=='forms_capabilities')$this->principal->assert($uid,(bool)($definition['requiresAdmin']??false));
            $availabilityDefinition=$definition;
            // Native fresh role admission above owns this check; a second memoized manager read can only be stale.
            unset($availabilityDefinition['requiresAdmin']);
            $availability=new ToolAvailability($fresh,$this->apps,$this->users,$this->guests,null,$this->groups);
            if(!$availability->allowed($availabilityDefinition,$uid))throw new ToolFailure(ToolFailure::FORBIDDEN);
            foreach(FormsAuthorization::operations($tool,$arguments,$snapshot) as $operation) {
                if(!in_array($operation,GrantPolicy::CATALOG['forms'],true)||!$fresh->granted($uid,'forms',$operation))throw new ToolFailure(ToolFailure::FORBIDDEN);
                if(isset($definition['grantAnyOf'])&&!in_array($operation,$selection['variants'][$tool]??[],true))throw new ToolFailure(ToolFailure::FORBIDDEN);
            }
            foreach(FormsAuthorization::fileOperations($tool,$arguments,$snapshot) as $operation)if(!in_array($operation,GrantPolicy::CATALOG['files'],true)||!$fresh->granted($uid,'files',$operation))throw new ToolFailure(ToolFailure::FORBIDDEN);
        });
    }
}
