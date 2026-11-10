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
    public function __construct(private IAppManager $apps,private ?MailDraftStorage $draftStorage=null){}
    // Flip only after the v4 exact-cache and real-protocol qualification passes.
    public const TAG_ASSIGNMENT_QUALIFIED=true;
    public const REFRESH_QUALIFIED=true;
    public const DRAFT_IDENTITY_QUALIFIED=true;
    // Qualified only for the exact v7 artifact, originating-page binding and native guarantees.
    public const CREDENTIAL_WRITES_QUALIFIED=true;
    public function credentialsSourceVerified():bool {return (NativeGuardManifest::DATA['contract']??null)==='mcp-mail-mutations-v7'&&$this->apps->getAppVersion('mail')===(NativeGuardManifest::DATA['build_version']??null)&&$this->matches(NativeGuardManifest::DATA);}
    public function draftsVerified():bool {return self::DRAFT_IDENTITY_QUALIFIED&&$this->draftsSourceVerified()&&($this->draftStorage?->verified()??false);}
    public function draftsSourceVerified():bool {return (in_array(NativeGuardManifest::DATA['contract']??null,['mcp-mail-mutations-v6','mcp-mail-mutations-v7'],true)&&$this->apps->getAppVersion('mail')===(NativeGuardManifest::DATA['build_version']??null)&&$this->matches(NativeGuardManifest::DATA))||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA);}
    public function refreshVerified():bool {return self::REFRESH_QUALIFIED&&$this->refreshSourceVerified();}
    public function refreshSourceVerified():bool {return in_array(NativeGuardManifest::DATA['contract']??null,['mcp-mail-mutations-v5','mcp-mail-mutations-v6','mcp-mail-mutations-v7'],true)&&$this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_REFRESH_DATA)||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA);}
    public function verified():bool {return $this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_FLAGS_DATA)||$this->matches(NativeGuardManifest::LEGACY_MOVES_DATA)||$this->matches(NativeGuardManifest::LEGACY_FOLDERS_DATA)||$this->matches(NativeGuardManifest::LEGACY_TAGS_DATA)||$this->matches(NativeGuardManifest::LEGACY_REFRESH_DATA)||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA);}
    public function movesVerified():bool {return $this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_MOVES_DATA)||$this->matches(NativeGuardManifest::LEGACY_FOLDERS_DATA)||$this->matches(NativeGuardManifest::LEGACY_TAGS_DATA)||$this->matches(NativeGuardManifest::LEGACY_REFRESH_DATA)||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA);}
    public function foldersVerified():bool {return in_array(NativeGuardManifest::DATA['contract']??null,['mcp-mail-mutations-v3','mcp-mail-mutations-v4','mcp-mail-mutations-v5','mcp-mail-mutations-v6','mcp-mail-mutations-v7'],true)&&$this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_FOLDERS_DATA)||$this->matches(NativeGuardManifest::LEGACY_TAGS_DATA)||$this->matches(NativeGuardManifest::LEGACY_REFRESH_DATA)||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA);}
    public function tagsSourceVerified():bool {return in_array(NativeGuardManifest::DATA['contract']??null,['mcp-mail-mutations-v4','mcp-mail-mutations-v5','mcp-mail-mutations-v6','mcp-mail-mutations-v7'],true)&&$this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_TAGS_DATA)||$this->matches(NativeGuardManifest::LEGACY_REFRESH_DATA)||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA);}
    public function tagsVerified():bool {return self::TAG_ASSIGNMENT_QUALIFIED&&$this->tagsSourceVerified();}
    private function matches(array $manifest):bool {
        try {
            if(!in_array($manifest['contract']??null,['mcp-mail-flags-v1','mcp-mail-mutations-v2','mcp-mail-mutations-v3','mcp-mail-mutations-v4','mcp-mail-mutations-v5','mcp-mail-mutations-v6','mcp-mail-mutations-v7'],true)||($manifest['mail']??null)!=='5.12.5'||!is_array($manifest['files']??null)||count($manifest['files'])<7)return false;
            $root=$this->apps->getAppPath('mail');
            foreach($manifest['files'] as $path=>$hash){
                if(!is_string($path)||str_contains($path,'..')||str_starts_with($path,'/')||!is_string($hash)||!is_file($root.'/'.$path)||!hash_equals($hash,(string)hash_file('sha256',$root.'/'.$path)))return false;
            }
            return true;
        } catch(\Throwable){return false;}
    }
}
