<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Access;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\{ConnectionContext,ConnectionToolsStore};
use OCP\{IUserManager};
use OCP\App\IAppManager;
/** One fresh coherent policy window at each local/native effect, never the enclosing HTTP snapshot. */
final class MailFreshAccess {
    public function __construct(private GrantPolicy $policy,private IAppManager $apps,private IUserManager $users,private GuestRestrictions $guests,
        private ConnectionContext $connection,private ConnectionToolsStore $selections,private OperationJournal $journal){}
    public function assertActive(string $uid):void {
        $context=$this->journal->activeAuthorization($uid);$this->assertContext($uid,$context);
    }
    /** Pin the original request once; native callbacks must never adopt a later active operation. */
    public function activeFence(string $uid,string $tool):\Closure {
        $context=$this->journal->activeAuthorization($uid);
        if($context['tool']!==$tool)throw new ToolFailure('Mail authorization changed.');
        $this->assertContext($uid,$context);
        return fn()=> $this->assertContext($uid,$context);
    }
    /** Native code supplies an independently owner-verified resource before private reads. */
    public function assertResource(string $uid,string $tool,int $account,int $mailbox=0):void {
        $this->assertContext($uid,['actor_incarnation'=>$this->journal->actor($uid),'tool'=>$tool,'refs'=>['v'=>1,'mail'=>[['account'=>$account,'mailbox'=>$mailbox]],'global'=>false,'files'=>[],'tools'=>[]]]);
    }
    private function assertContext(string $uid,array $context):void {
        $this->journal->assertActor($uid,$context['actor_incarnation']);
        $connection=$this->connection->current();
        $this->policy->freshWithin($uid,function(GrantPolicy $fresh)use($uid,$context,$connection):void {
            $this->journal->assertActor($uid,$context['actor_incarnation']);
            $user=$this->users->get($uid);
            if($user===null||!$user->isEnabled()||!$this->apps->isEnabledForUser('mcp',$user)||!$fresh->canConnect($uid))throw new ToolFailure('Mail authorization changed.');
            $selection=$this->selections->get($uid,$connection);
            if($selection===null)throw new ToolFailure('Mail authorization changed.');
            $availability=new ToolAvailability($fresh,$this->apps,$this->users,$this->guests);
            $tools=array_values(array_unique([$context['tool'],...($context['refs']['tools']??[])]));
            if(count($tools)>10)throw new ToolFailure('Mail authorization changed.');
            $definitions=\OCA\Mcp\Modules\Mail\MailToolCatalog::definitions()+\OCA\Mcp\Modules\Files\FilesToolCatalog::definitions();
            foreach($tools as $tool)if(!is_string($tool)||!isset($definitions[$tool])||!in_array($tool,$selection['tools'],true)||!$availability->allowed($definitions[$tool],$uid))throw new ToolFailure('Mail authorization changed.');
            (new MailAccessGuard($fresh))->authorizeReferences($uid,$context['refs']);
        });
    }
}
