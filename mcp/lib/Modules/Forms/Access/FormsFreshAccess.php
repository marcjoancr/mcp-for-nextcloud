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
use OCA\Mcp\Modules\Forms\Execution\FormsBulkAuthority;
use OCA\Mcp\Modules\Forms\Integration\FormsPublicReads;
use OCA\Mcp\Modules\Forms\Integration\FormsOfficialAuthoring;
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
        $this->assertWithinPolicy($uid,$tool,$arguments,$snapshot,false);
    }
    public function assertPublicRead(string $uid,string $tool,array $arguments):void {
        if(!in_array($tool,FormsPublicReads::TOOLS,true))throw new ToolFailure(ToolFailure::FORBIDDEN);
        $this->assertWithinPolicy($uid,$tool,$arguments,[],true);
    }
    public function assertOfficialWrite(string $uid,string $tool,array $arguments,array $snapshot=[]):void {
        if(!in_array($tool,FormsOfficialAuthoring::TOOLS,true))throw new ToolFailure(ToolFailure::FORBIDDEN);
        $this->assertWithinPolicy($uid,$tool,$arguments,$snapshot,true);
    }
    private function assertWithinPolicy(string $uid,string $tool,array $arguments,array $snapshot,bool $official):void {
        $this->policy->freshWithin($uid,function(GrantPolicy $fresh)use($uid,$tool,$arguments,$snapshot,$official):void {
            $user=$this->users->get($uid);$connection=$this->connection->current();
            if($user===null||!$user->isEnabled()||$this->session->getUser()?->getUID()!==$uid
                ||!$this->apps->isEnabledForUser('mcp',$user)||!$fresh->canConnect($uid))throw new ToolFailure(ToolFailure::FORBIDDEN);
            $selection=$this->selections->get($uid,$connection);
            if($selection===null)throw new ToolFailure(ToolFailure::FORBIDDEN);
            $requiresAdmin=$this->admit($fresh,$uid,$tool,$arguments,$snapshot,$selection,$official);
            $bulk=$snapshot['bulk_authority']??$snapshot['historical']['bulk_authority']??null;
            if($bulk!==null) {
                $authority=FormsBulkAuthority::checked($bulk);
                $requiresAdmin=$this->admit($fresh,$uid,$authority['tool'],[],['historical'=>$authority],$selection)||$requiresAdmin;
            }
            if($official) {
                if($requiresAdmin)throw new ToolFailure(ToolFailure::FORBIDDEN);
            } elseif($tool!=='forms_capabilities'||$bulk!==null) {
                $this->principal->assert($uid,$requiresAdmin);
            }
        });
    }
    private function admit(GrantPolicy $fresh,string $uid,string $tool,array $arguments,array $snapshot,array $selection,bool $official=false):bool {
        $definition=FormsCommands::definitions()[$tool]??throw new ToolFailure(ToolFailure::FORBIDDEN);
        if(!in_array($tool,$selection['tools'],true))throw new ToolFailure(ToolFailure::FORBIDDEN);
        $availabilityDefinition=$definition;
        // Native fresh role admission at the end of this window owns this check; a second memoized manager read can only be stale.
        unset($availabilityDefinition['requiresAdmin']);
        $availability=new ToolAvailability($fresh,$this->apps,$this->users,$this->guests->fresh(),null,$this->groups);
        if(!$availability->allowed($availabilityDefinition,$uid))throw new ToolFailure(ToolFailure::FORBIDDEN);
        foreach(FormsAuthorization::operations($tool,$arguments,$snapshot) as $operation) {
            // The availability check already verified the independent per-tool grant for simple official operations and capability discovery.
            if(($official||$tool==='forms_capabilities')&&!isset($definition['grantAnyOf'])&&$operation===$definition['operation'])continue;
            if(!in_array($operation,GrantPolicy::CATALOG['forms'],true)||!$fresh->granted($uid,'forms',$operation))throw new ToolFailure(ToolFailure::FORBIDDEN);
            if(isset($definition['grantAnyOf'])&&(!in_array($operation,$selection['variants'][$tool]??[],true)
                ||($official&&!$fresh->toolGranted($uid,$tool,'forms',$operation))))throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        foreach(FormsAuthorization::fileOperations($tool,$arguments,$snapshot) as $operation)if(!in_array($operation,GrantPolicy::CATALOG['files'],true)||!$fresh->granted($uid,'files',$operation))throw new ToolFailure(ToolFailure::FORBIDDEN);
        return (bool)($definition['requiresAdmin']??false);
    }
}
