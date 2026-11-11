<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Access;
use OCA\Mcp\Access\ResourceScope\ScopeReader;
use OCA\Mcp\Access\ResourceScope\ResourceIdentity;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Pure policy narrowing. Callers independently prove current ownership and account/mailbox membership. */
final class MailAccessGuard {
    public function __construct(private ScopeReader $policy) {}
    public function assertReadable(string $uid): void { $this->policy->assertReadable($uid,'mail'); }
    /** null is unrestricted, [] denies everything. Uses the grant's same policy snapshot. */
    public function items(string $uid): ?array { $this->assertReadable($uid); return $this->policy->scopeItems($uid,'mail'); }
    public function unrestricted(string $uid): bool { return $this->items($uid) === null; }
    public function accountAllowed(string $uid,int $account): bool {
        $items=$this->items($uid);
        ResourceIdentity::validate('mail','account:'.$account);
        return $items===null || in_array('account:'.$account,$items,true);
    }
    public function mailboxAllowed(string $uid,int $account,int $mailbox): bool {
        $items=$this->items($uid);
        ResourceIdentity::validate('mail','account:'.$account); ResourceIdentity::validate('mail','mailbox:'.$mailbox);
        return $items===null || in_array('account:'.$account,$items,true) || in_array('mailbox:'.$mailbox,$items,true);
    }
    public function requireAccount(string $uid,int $account): void { if(!$this->accountAllowed($uid,$account))self::deny(); }
    public function requireMailbox(string $uid,int $account,int $mailbox): void { if(!$this->mailboxAllowed($uid,$account,$mailbox))self::deny(); }
    public function requireUnrestricted(string $uid): void { if(!$this->unrestricted($uid))self::deny(); }
    /** Only trusted immutable journal/native-store references may use this historical path. No live lookup. */
    public function authorizeReferences(string $uid,array $refs):void {
        $this->assertReadable($uid);
        if(($refs['v']??null)!==1||!is_array($refs['mail']??null)||!array_is_list($refs['mail'])||count($refs['mail'])>200||!is_bool($refs['global']??null))self::deny();
        if($refs['global'])$this->requireUnrestricted($uid);
        if(!$refs['global']&&$refs['mail']===[])self::deny();
        if(isset($refs['files'])){
            if(!is_array($refs['files'])||!array_is_list($refs['files'])||count($refs['files'])>20)self::deny();
            $items=$refs['files']===[]?null:$this->policy->scopeItems($uid,'files');
            foreach($refs['files'] as $chain){
                if(!is_array($chain)||!array_is_list($chain)||$chain===[]||count($chain)>256)self::deny();
                foreach($chain as $id)if(!is_string($id)||!ResourceIdentity::valid('files',$id))self::deny();
                if($items!==null&&array_intersect($items,$chain)===[])self::deny();
            }
        }
        foreach($refs['mail'] as $r){
            if(!is_array($r)||!is_int($r['account']??null)||$r['account']<1||!is_int($r['mailbox']??null)||$r['mailbox']<0)self::deny();
            if($r['mailbox']===0)$this->requireAccount($uid,$r['account']);else $this->requireMailbox($uid,$r['account'],$r['mailbox']);
        }
    }
    private static function deny(): never { throw new ToolFailure('Mail resource not found.'); }
}
