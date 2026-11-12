<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Modules\Mail\Access\{MailAccessGuard,MailFreshAccess};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IConfig;
/** Only this boundary resolves the native mode service; no credential or signature bytes cross it. */
class MailSignatureModeGateway {
    public function __construct(private MailServices $services,private MailNativeBoundary $boundary,private MailAliasBoundary $storage,private MailAccessGuard $access,private MailFreshAccess $fresh,private IConfig $config){}
    private function native(string $uid):object {
        if(!$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))||!$this->boundary->signatureModeVerified())throw new ToolFailure('Qualified native signature mode changes are unavailable.');
        $this->storage->requireSupported();return $this->services->guardedSignatureMode($uid);
    }
    public function read(string $uid,int $account,?int $alias):array {
        $this->access->requireAccount($uid,$account);$guard=$this->fresh->resourceFence($uid,'mail_write_signature_mode',$account);
        try{$r=$this->native($uid)->read($uid,$account,$alias,$guard);}catch(\Throwable){$guard();throw new ToolFailure('Signature identity unavailable, changed, provisioned or unsupported.');}$guard();return $r;
    }
    public function write(string $uid,int $account,?int $alias,int $mode,string $revision,\Closure $guard):array {
        $guard();try{return $this->native($uid)->write($uid,$account,$alias,$mode,$revision,$guard);}catch(\Throwable){$guard();throw new ToolFailure('Signature mode write refused. Inspect the same operation ID.');}
    }
    public function assertOwner(string $uid):void {$this->native($uid)->assertOwner($uid);}
}
