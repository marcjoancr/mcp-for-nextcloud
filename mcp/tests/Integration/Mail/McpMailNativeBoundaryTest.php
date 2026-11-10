<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailCredentialStorage,MailDraftStorage,MailNativeBoundary,MailServices,NativeDraftLinks,NativeGuardManifest,NativeSetupEvidence};
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\{App\IAppManager,IConfig,IDBConnection,IURLGenerator,Server};
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailNativeBoundaryTest extends IntegrationTestCase {
    public function testOnlyQualifiedV2AdmitsMovesAndLegacyStillAdmitsFlagsAndTamperAdmitsNeither():void {
        if(getenv('MCP_IT_WITH_MAIL')!=='1')self::markTestSkipped('Mail fixture only');
        $r=MailActor::run(MailActor::ALICE,'c3-boundary');$guard=getenv('MCP_IT_MAIL_GUARD');
        $known=getenv('MCP_IT_NC')==='35'||$guard==='1';
        self::assertSame(in_array($guard,['1','legacy','v2'],true)?'verified':($known?'blocked':'unverified'),$r['before']['capabilities']['flags']['status']);
        self::assertSame(in_array($guard,['1','v2'],true)?'verified':($known?'blocked':'unverified'),$r['before']['capabilities']['move']['status']);
        self::assertSame($guard==='1'?'verified':'unverified',$r['before']['capabilities']['create_mailbox']['status']);
        self::assertSame('disabled',$r['before']['capabilities']['send']['status']);self::assertTrue($r['no_send']);

        $realApps=Server::get(IAppManager::class);$installedRoot=$realApps->getAppPath('mail');$installedVersion=$realApps->getAppVersion('mail');
        $manifests=[NativeGuardManifest::DATA,NativeGuardManifest::LEGACY_FLAGS_DATA,NativeGuardManifest::LEGACY_MOVES_DATA,NativeGuardManifest::LEGACY_FOLDERS_DATA,
            NativeGuardManifest::LEGACY_TAGS_DATA,NativeGuardManifest::LEGACY_REFRESH_DATA,NativeGuardManifest::LEGACY_DRAFTS_DATA,
            NativeGuardManifest::LEGACY_CREDENTIALS_DATA,NativeGuardManifest::LEGACY_RUNTIME_DATA];
        $paths=[];foreach($manifests as $manifest)foreach(array_keys($manifest['files']??[]) as $relative)$paths[$relative]=true;
        $root=sys_get_temp_dir().'/mcp-mail-boundary-'.bin2hex(random_bytes(16));if(!mkdir($root,0700))throw new \RuntimeException('Cannot create owned native boundary fixture directory.');
        $files=[];$directories=[$root];
        try {
            foreach(array_keys($paths) as $relative){
                if(!is_string($relative)||$relative===''||str_starts_with($relative,'/')||str_contains($relative,'..')||str_contains($relative,'\\'))throw new \LogicException('Unsafe native manifest fixture path.');
                $source=$installedRoot.'/'.$relative;if(!is_file($source))continue;
                $parent=$root;foreach(explode('/',dirname($relative)) as $part){if($part==='.'||$part==='')continue;$parent.='/'.$part;if(!is_dir($parent)){if(!mkdir($parent,0700))throw new \RuntimeException('Cannot create native boundary fixture subdirectory.');$directories[]=$parent;}}
                $target=$root.'/'.$relative;$files[]=$target;if(!copy($source,$target))throw new \RuntimeException('Cannot copy installed native fingerprint source.');
            }
            $mockApps=$this->createMock(IAppManager::class);$mockApps->method('getAppPath')->willReturn($root);$mockApps->method('getAppVersion')->willReturn($installedVersion);
            $copyBoundary=new MailNativeBoundary($mockApps);$installedBoundary=new MailNativeBoundary($realApps);
            $storage=$this->createMock(MailDraftStorage::class);$storage->method('verified')->willReturn(true);
            $draftBoundary=new MailNativeBoundary($mockApps,$storage);$urls=$this->createMock(IURLGenerator::class);$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.test/apps/mail');
            $draftLinks=new NativeDraftLinks($draftBoundary,$urls,Server::get(IConfig::class));$installedDraftLinks=new NativeDraftLinks(new MailNativeBoundary($realApps,$storage),$urls,Server::get(IConfig::class));
            $draft=new class {
                public function getMcpDraftOnly():bool{return true;}
                public function getSmimeSign():bool{return false;}
                public function getSmimeEncrypt():bool{return false;}
                public function isPgpMime():bool{return false;}
                public function getSendAt():mixed{return null;}
            };
            $editUrl=$draftLinks->forDraft(71,$draft);self::assertSame($installedDraftLinks->forDraft(71,$draft),$editUrl,'The owned source copy must preserve the installed version’s edit-entry capability, including stock and legacy versions.');
            $setupStorage=$this->createMock(MailCredentialStorage::class);$setupStorage->method('verified')->willReturn(true);
            $setupServices=$this->createMock(MailServices::class);$setupServices->expects(self::never())->method('clientFactory');$setupServices->expects(self::never())->method('smtpFactory');$setupServices->expects(self::never())->method('setupEvidence');
            $config=Server::get(IConfig::class);$db=Server::get(IDBConnection::class);$setupCopyBoundary=new MailNativeBoundary($mockApps);
            $setupCopy=new NativeSetupEvidence($setupServices,$setupCopyBoundary,$setupStorage,$db,$config);
            $setupInstalled=new NativeSetupEvidence($setupServices,new MailNativeBoundary($realApps),$setupStorage,$db,$config);
            $setupBaseline=$setupCopy->available();$setupSourceBaseline=$setupCopyBoundary->credentialsSourceVerified();
            self::assertSame($setupInstalled->available(),$setupBaseline,'The owned source copy must preserve the installed setup evidence capability.');
            self::assertSame((new MailNativeBoundary($realApps))->credentialsSourceVerified(),$setupSourceBaseline,'The owned source copy must preserve the installed credential source verification.');
            $setupTarget=$root.'/lib/Authorization/SetupEvidence.php';
            if(is_file($setupTarget)){
                $setupOriginal=file_get_contents($setupTarget);if(!is_string($setupOriginal))throw new \RuntimeException('Cannot read copied credential source fingerprint.');
                try {$setupBytes=$setupOriginal."\n// disposable setup evidence fingerprint mismatch\n";$written=file_put_contents($setupTarget,$setupBytes);if($written!==strlen($setupBytes))throw new \RuntimeException('Could not completely tamper the owned setup evidence copy.');clearstatcache(true,$setupTarget);self::assertFalse($setupCopyBoundary->credentialsSourceVerified(),'A changed setup evidence source must fail credential source verification.');self::assertFalse($setupCopy->available(),'A changed setup evidence source must suppress native setup evidence.');}
                finally {$restored=file_put_contents($setupTarget,$setupOriginal);if($restored!==strlen($setupOriginal))throw new \RuntimeException('Could not restore the owned setup evidence copy.');clearstatcache(true,$setupTarget);self::assertSame(hash('sha256',$setupOriginal),hash_file('sha256',$setupTarget));}
                self::assertSame($setupSourceBaseline,$setupCopyBoundary->credentialsSourceVerified(),'Restoring setup evidence source must restore its exact fingerprint.');self::assertSame($setupBaseline,$setupCopy->available(),'Restoring setup evidence source must restore the installed setup capability.');
            }else{
                self::assertFalse($setupBaseline,'A source profile without SetupEvidence.php cannot provide native credential setup evidence.');
            }
            $before=['flags'=>$copyBoundary->verified(),'move'=>$copyBoundary->movesVerified()];
            self::assertSame(['flags'=>$installedBoundary->verified(),'move'=>$installedBoundary->movesVerified()],$before,'The owned copy must preserve current, legacy, stock, and v9 source verification.');
            $target=$root.'/lib/IMAP/HordeImapClient.php';
            self::assertFileExists($target,'The copied native move target must exist so the tamper check is exercised.');
            if(is_file($target)){
                $original=file_get_contents($target);if(!is_string($original))throw new \RuntimeException('Cannot read copied native fingerprint source.');
                $tampered=false;
                try {$written=file_put_contents($target,$original."\n// disposable fingerprint mismatch\n");if($written!==strlen($original."\n// disposable fingerprint mismatch\n"))throw new \RuntimeException('Could not completely tamper the owned fixture copy.');$tampered=true;clearstatcache(true,$target);
                    self::assertSame(['flags'=>false,'move'=>false],['flags'=>$copyBoundary->verified(),'move'=>$copyBoundary->movesVerified()]);
                } finally {if($tampered){$restored=file_put_contents($target,$original);if($restored!==strlen($original))throw new \RuntimeException('Could not restore the owned native fixture copy.');clearstatcache(true,$target);self::assertSame(hash('sha256',$original),hash_file('sha256',$target));}}
            }
            self::assertSame($before,['flags'=>$copyBoundary->verified(),'move'=>$copyBoundary->movesVerified()],'Restoring the copied source must restore its exact native fingerprint.');
            $editTarget=$root.'/js/mail.js';self::assertFileExists($editTarget,'The copied editor fingerprint source must exist so its tamper check is exercised.');
            $editorOriginal=file_get_contents($editTarget);if(!is_string($editorOriginal))throw new \RuntimeException('Cannot read copied native editor fingerprint source.');
            try {$editorBytes=$editorOriginal."\n// disposable editor fingerprint mismatch\n";$written=file_put_contents($editTarget,$editorBytes);if($written!==strlen($editorBytes))throw new \RuntimeException('Could not completely tamper the owned editor fixture copy.');clearstatcache(true,$editTarget);self::assertNull($draftLinks->forDraft(71,$draft),'A changed editor artifact must suppress the native edit entry.');}
            finally {$restored=file_put_contents($editTarget,$editorOriginal);if($restored!==strlen($editorOriginal))throw new \RuntimeException('Could not restore the owned editor fixture copy.');clearstatcache(true,$editTarget);self::assertSame(hash('sha256',$editorOriginal),hash_file('sha256',$editTarget));}
            self::assertSame($editUrl,$draftLinks->forDraft(71,$draft),'Restoring the copied editor artifact must restore the native edit entry.');
        } finally {
            foreach(array_reverse($files) as $file)if(is_file($file)&&!unlink($file))throw new \RuntimeException('Cannot remove owned native fixture file.');
            foreach(array_reverse($directories) as $directory)if(is_dir($directory)&&!rmdir($directory))throw new \RuntimeException('Cannot remove owned native fixture directory.');
        }
    }
}
