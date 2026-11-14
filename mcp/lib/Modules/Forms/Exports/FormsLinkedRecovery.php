<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\IUserManager;
/** Verify the old bytes at actual content admission; native pre-write listeners have run at that stage.
 * The caller separately admits original authority, backup privacy and supported storage at each effect.
 */
final class FormsLinkedRecovery {
    public function __construct(private IRootFolder $root,private IUserManager $users,private \Closure $versions,private \Closure $copiesRequired,private \Closure $versionsEnabled) {}
    private function manager(\OCP\Files\Node $file):object {
        $manager=($this->versions)();
        if(get_class($manager)!=='OCA\\Files_Versions\\Versions\\VersionManager'
            ||get_class($manager->getBackendForStorage($file->getStorage()))!=='OCA\\Files_Versions\\Versions\\LegacyVersionsBackend')
            throw new \RuntimeException('The qualified native Versions backend is unavailable.');
        return $manager;
    }
    public function qualify(string $uid,\OCP\Files\Node $node):void {
        if(($this->versionsEnabled)($uid)!==true)throw new \RuntimeException('Native Versions must be enabled before linking a future Forms export.');
        if(!($this->copiesRequired)($uid))$this->manager($node);
    }
    public function capture(string $uid,File $file,array $preimage):array {
        if(!is_int($preimage['size']??null)||$preimage['size']<0||$preimage['size']>26214400
            ||preg_match('/^[a-f0-9]{64}$/D',$preimage['sha256']??'')!==1||(int)$file->getId()<1)
            throw new \RuntimeException('Linked Forms recovery preimage is unavailable.');
        $copies=($this->copiesRequired)($uid);
        $revision=$copies?null:$this->manager($file)->getRevision($file);
        if(!$copies&&(!is_int($revision)||$revision<1))throw new \RuntimeException('Native Versions recovery revision is unavailable.');
        return ['uid'=>$uid,'file_id'=>(int)$file->getId(),'preimage'=>$preimage,'copy_required'=>$copies,'revision'=>$revision];
    }
    public function assert(string $uid,File $file,array $proof,string $copy,string $stage):void {
        if(($proof['uid']??null)!==$uid||($proof['file_id']??null)!==(int)$file->getId())
            throw new \RuntimeException('Linked Forms recovery identity changed.');
        if($copy!=='') {
            $saved=$this->root->getUserFolder($uid)->get(ltrim($copy,'/'));
            if(!$saved instanceof File||(int)$saved->getId()<1||(int)$saved->getId()===(int)$file->getId())
                throw new \RuntimeException('An independent Forms recovery copy is unavailable.');
            $this->bytes($saved,$proof['preimage']);return;
        }
        if($proof['copy_required']||($this->copiesRequired)($uid))
            throw new \RuntimeException('The required Forms recovery copy is unavailable.');
        if($stage!=='content')return;
        $user=$this->users->get($uid)??throw new \RuntimeException('Original Versions actor is unavailable.');
        $saved=$this->manager($file)->getVersionFile($user,$file,$proof['revision']);
        if(!$saved instanceof File)throw new \RuntimeException('Native Versions recovery file is unavailable.');
        $this->bytes($saved,$proof['preimage']);
    }
    private function bytes(File $file,array $expected):void {
        $stream=$file->fopen('rb');
        if(!is_resource($stream))throw new \RuntimeException('Forms recovery preimage cannot be read.');
        try {$hash=hash_init('sha256');$size=hash_update_stream($hash,$stream,26214401);$digest=hash_final($hash);}finally{fclose($stream);}
        if($size!==$expected['size']||!hash_equals($expected['sha256'],$digest))
            throw new \RuntimeException('Forms recovery preimage does not match the approved old bytes.');
    }
}
