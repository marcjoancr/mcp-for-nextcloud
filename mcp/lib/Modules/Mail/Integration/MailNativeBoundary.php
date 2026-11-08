<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCP\App\IAppManager;
/** Read-only native artifact identity. Presence/version alone never admits a guarded write. */
class MailNativeBoundary {
    public function __construct(private IAppManager $apps){}
    public function verified():bool {return $this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_FLAGS_DATA);}
    public function movesVerified():bool {return (NativeGuardManifest::DATA['contract']??null)==='mcp-mail-mutations-v2'&&$this->matches(NativeGuardManifest::DATA);}
    private function matches(array $manifest):bool {
        try {
            if(!in_array($manifest['contract']??null,['mcp-mail-flags-v1','mcp-mail-mutations-v2'],true)||($manifest['mail']??null)!=='5.12.5'||!is_array($manifest['files']??null)||count($manifest['files'])<7)return false;
            $root=$this->apps->getAppPath('mail');
            foreach($manifest['files'] as $path=>$hash){
                if(!is_string($path)||str_contains($path,'..')||str_starts_with($path,'/')||!is_string($hash)||!is_file($root.'/'.$path)||!hash_equals($hash,(string)hash_file('sha256',$root.'/'.$path)))return false;
            }
            return true;
        } catch(\Throwable){return false;}
    }
}
