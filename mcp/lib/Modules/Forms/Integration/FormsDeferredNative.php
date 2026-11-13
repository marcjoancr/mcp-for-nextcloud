<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Forms\Exports\FormsExportJobs;
use OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Deferred reads use the originating account's live policy and a separately qualified native actor boundary. */
final class FormsDeferredNative {
    /** @param \Closure():object $guard @param \Closure():bool $identity */
    public function __construct(private \Closure $guard,private \Closure $identity,private FormsNativeAuthorization $authority,private GrantPolicy $policy) {}
    public function read(array $origin,string $action,array $args):array {
        $context=$this->authority->assert($origin);
        if(!(($this->identity)()))throw new ToolFailure('The original Forms runtime is unavailable.');
        return $this->policy->freshWithin($context['uid'],function()use($origin,$action,$args):array {
            $this->authority->assert($origin);$guard=($this->guard)();
            if(!method_exists($guard,'deferredRead'))throw new ToolFailure('Deferred Forms authority has not been qualified.');
            return $guard->deferredRead($origin,$action,$args);
        });
    }
    public function assertJob(array $job):void {
        $p=$job['payload'];$current=$this->read($p['origin'],'snapshot',['id'=>$p['arguments']['id']]);
        if(FormsExportJobs::sourceRevision($current)!==$p['source_revision']||($current['form']['ownerId']??null)!==$p['metadata']['form']['ownerId'])throw new ToolFailure('The original Forms result authority or source changed.');
    }
}
