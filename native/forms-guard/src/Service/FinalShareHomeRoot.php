<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Private DTO dependency for the pinned FilesSharing handler; never registered in Core DI. */
final class FinalShareHomeRoot extends \OC\Files\Node\Root {
    public function __construct(private readonly string $subject,private readonly \OC\Files\Node\Folder $home) {
        FinalNativeAcl::initialized($home);
        if ((new \ReflectionProperty(\OC\Files\Node\Node::class,'fileInfo'))->getValue($home)::class!==\OC\Files\FileInfo::class) {throw new \RuntimeException('Final sharing home metadata is unavailable');}
        if ($home->getPath()!=='/'.$subject.'/files' || $home->getId()<1) {throw new \RuntimeException('Final sharing home identity changed');}
    }
    public function getUserFolder($userId) {
        if ($userId!==$this->subject) {throw new \RuntimeException('Final sharing subject changed');}
        return $this->home;
    }
}
