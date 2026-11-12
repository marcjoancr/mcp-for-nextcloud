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
    public function runtimeMatches(string $core):bool {return MailRuntimePairs::matches($core,$this->apps->getAppVersion('mail'));}
    public function credentialsSourceVerified():bool {return $this->credentialManifest(NativeGuardManifest::DATA)||$this->credentialManifest(NativeGuardManifest::LEGACY_CREDENTIALS_DATA)||$this->credentialManifest(NativeGuardManifest::LEGACY_RUNTIME_DATA)||$this->credentialManifest(NativeGuardManifest::LEGACY_POSTGRESQL_DATA)||$this->credentialManifest(NativeGuardManifest::LEGACY_METADATA_DATA);}
    private function credentialManifest(array $data):bool {return in_array($data['contract']??null,['mcp-mail-mutations-v7','mcp-mail-mutations-v8','mcp-mail-mutations-v9','mcp-mail-mutations-v10','mcp-mail-mutations-v11','mcp-mail-mutations-v12','mcp-mail-mutations-v13'],true)&&$this->apps->getAppVersion('mail')===($data['build_version']??null)&&$this->matches($data);}
    public function draftsVerified():bool {return self::DRAFT_IDENTITY_QUALIFIED&&$this->draftsSourceVerified()&&($this->draftStorage?->verified()??false);}
    public function draftsSourceVerified():bool {return (in_array(NativeGuardManifest::DATA['contract']??null,['mcp-mail-mutations-v6','mcp-mail-mutations-v7','mcp-mail-mutations-v8','mcp-mail-mutations-v9','mcp-mail-mutations-v10','mcp-mail-mutations-v11','mcp-mail-mutations-v12','mcp-mail-mutations-v13'],true)&&$this->apps->getAppVersion('mail')===(NativeGuardManifest::DATA['build_version']??null)&&$this->matches(NativeGuardManifest::DATA))||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA)||$this->matches(NativeGuardManifest::LEGACY_CREDENTIALS_DATA)||$this->matches(NativeGuardManifest::LEGACY_RUNTIME_DATA)||$this->matches(NativeGuardManifest::LEGACY_POSTGRESQL_DATA)||$this->matches(NativeGuardManifest::LEGACY_METADATA_DATA);}
    public function refreshVerified():bool {return self::REFRESH_QUALIFIED&&$this->refreshSourceVerified();}
    public function refreshSourceVerified():bool {return in_array(NativeGuardManifest::DATA['contract']??null,['mcp-mail-mutations-v5','mcp-mail-mutations-v6','mcp-mail-mutations-v7','mcp-mail-mutations-v8','mcp-mail-mutations-v9','mcp-mail-mutations-v10','mcp-mail-mutations-v11','mcp-mail-mutations-v12','mcp-mail-mutations-v13'],true)&&$this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_REFRESH_DATA)||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA)||$this->matches(NativeGuardManifest::LEGACY_CREDENTIALS_DATA)||$this->matches(NativeGuardManifest::LEGACY_RUNTIME_DATA)||$this->matches(NativeGuardManifest::LEGACY_POSTGRESQL_DATA)||$this->matches(NativeGuardManifest::LEGACY_METADATA_DATA);}
    public function boundedMetadataSourceVerified():bool {
        foreach([NativeGuardManifest::DATA,NativeGuardManifest::LEGACY_METADATA_DATA] as $manifest){
            if(in_array($manifest['contract']??null,['mcp-mail-mutations-v10','mcp-mail-mutations-v11','mcp-mail-mutations-v12','mcp-mail-mutations-v13'],true)&&$this->apps->getAppVersion('mail')===($manifest['build_version']??null)&&$this->matches($manifest))return true;
        }
        return false;
    }
    /** Version metadata only: a failed new boundary must never select an older parser. */
    public function currentContentGeneration():bool {
        $version=$this->apps->getAppVersion('mail');
        return preg_match('/^[0-9]+\\.[0-9]+\\.[0-9]+(?:\\.[0-9]+)?$/D',$version)!==1||version_compare($version,'5.12.5.11','>=');
    }
    public const DELIVERY_QUALIFIED=true;
    public function deliverySourceVerified():bool {return (NativeGuardManifest::DATA['contract']??null)==='mcp-mail-mutations-v13'&&$this->apps->getAppVersion('mail')===(NativeGuardManifest::DATA['build_version']??null)&&$this->matches(NativeGuardManifest::DATA);}
    public const SIGNATURE_MODE_QUALIFIED=true;
    public function signatureModeVerified():bool {return self::SIGNATURE_MODE_QUALIFIED&&$this->deliverySourceVerified();}
    public const SIEVE_QUALIFIED=true;
    public function sieveVerified():bool {return self::SIEVE_QUALIFIED&&$this->deliverySourceVerified();}
    public const SNOOZE_QUALIFIED=true;
    public function snoozeVerified():bool {return self::SNOOZE_QUALIFIED&&$this->deliverySourceVerified();}
    public function deliveryVerified():bool {return self::DELIVERY_QUALIFIED&&$this->deliverySourceVerified();}
    public function verified():bool {return $this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_FLAGS_DATA)||$this->matches(NativeGuardManifest::LEGACY_MOVES_DATA)||$this->matches(NativeGuardManifest::LEGACY_FOLDERS_DATA)||$this->matches(NativeGuardManifest::LEGACY_TAGS_DATA)||$this->matches(NativeGuardManifest::LEGACY_REFRESH_DATA)||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA)||$this->matches(NativeGuardManifest::LEGACY_CREDENTIALS_DATA)||$this->matches(NativeGuardManifest::LEGACY_RUNTIME_DATA)||$this->matches(NativeGuardManifest::LEGACY_POSTGRESQL_DATA)||$this->matches(NativeGuardManifest::LEGACY_METADATA_DATA);}
    public function movesVerified():bool {return $this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_MOVES_DATA)||$this->matches(NativeGuardManifest::LEGACY_FOLDERS_DATA)||$this->matches(NativeGuardManifest::LEGACY_TAGS_DATA)||$this->matches(NativeGuardManifest::LEGACY_REFRESH_DATA)||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA)||$this->matches(NativeGuardManifest::LEGACY_CREDENTIALS_DATA)||$this->matches(NativeGuardManifest::LEGACY_RUNTIME_DATA)||$this->matches(NativeGuardManifest::LEGACY_POSTGRESQL_DATA)||$this->matches(NativeGuardManifest::LEGACY_METADATA_DATA);}
    public function foldersVerified():bool {return in_array(NativeGuardManifest::DATA['contract']??null,['mcp-mail-mutations-v3','mcp-mail-mutations-v4','mcp-mail-mutations-v5','mcp-mail-mutations-v6','mcp-mail-mutations-v7','mcp-mail-mutations-v8','mcp-mail-mutations-v9','mcp-mail-mutations-v10','mcp-mail-mutations-v11','mcp-mail-mutations-v12','mcp-mail-mutations-v13'],true)&&$this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_FOLDERS_DATA)||$this->matches(NativeGuardManifest::LEGACY_TAGS_DATA)||$this->matches(NativeGuardManifest::LEGACY_REFRESH_DATA)||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA)||$this->matches(NativeGuardManifest::LEGACY_CREDENTIALS_DATA)||$this->matches(NativeGuardManifest::LEGACY_RUNTIME_DATA)||$this->matches(NativeGuardManifest::LEGACY_POSTGRESQL_DATA)||$this->matches(NativeGuardManifest::LEGACY_METADATA_DATA);}
    public function tagsSourceVerified():bool {return in_array(NativeGuardManifest::DATA['contract']??null,['mcp-mail-mutations-v4','mcp-mail-mutations-v5','mcp-mail-mutations-v6','mcp-mail-mutations-v7','mcp-mail-mutations-v8','mcp-mail-mutations-v9','mcp-mail-mutations-v10','mcp-mail-mutations-v11','mcp-mail-mutations-v12','mcp-mail-mutations-v13'],true)&&$this->matches(NativeGuardManifest::DATA)||$this->matches(NativeGuardManifest::LEGACY_TAGS_DATA)||$this->matches(NativeGuardManifest::LEGACY_REFRESH_DATA)||$this->matches(NativeGuardManifest::LEGACY_DRAFTS_DATA)||$this->matches(NativeGuardManifest::LEGACY_CREDENTIALS_DATA)||$this->matches(NativeGuardManifest::LEGACY_RUNTIME_DATA)||$this->matches(NativeGuardManifest::LEGACY_POSTGRESQL_DATA)||$this->matches(NativeGuardManifest::LEGACY_METADATA_DATA);}
    public function tagsVerified():bool {return self::TAG_ASSIGNMENT_QUALIFIED&&$this->tagsSourceVerified();}
    private function matches(array $manifest):bool {
        try {
            if(!in_array($manifest['contract']??null,['mcp-mail-flags-v1','mcp-mail-mutations-v2','mcp-mail-mutations-v3','mcp-mail-mutations-v4','mcp-mail-mutations-v5','mcp-mail-mutations-v6','mcp-mail-mutations-v7','mcp-mail-mutations-v8','mcp-mail-mutations-v9','mcp-mail-mutations-v10','mcp-mail-mutations-v11','mcp-mail-mutations-v12','mcp-mail-mutations-v13'],true)||($manifest['mail']??null)!=='5.12.5'||!is_array($manifest['files']??null)||count($manifest['files'])<7)return false;
            $root=$this->apps->getAppPath('mail');
            foreach($manifest['files'] as $path=>$hash){
                if(!is_string($path)||str_contains($path,'..')||str_starts_with($path,'/')||!is_string($hash)||!is_file($root.'/'.$path)||!hash_equals($hash,(string)hash_file('sha256',$root.'/'.$path)))return false;
            }
            return true;
        } catch(\Throwable){return false;}
    }
}
