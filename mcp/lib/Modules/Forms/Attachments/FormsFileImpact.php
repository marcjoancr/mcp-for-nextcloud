<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Attachments;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\Files\IRootFolder;
/** Checks native managed identities against current actor Files visibility before exposing or sharing them. */
final class FormsFileImpact {
    public function __construct(private IRootFolder $root,private FilesAccessGuard $access) {}
    /** The qualified native lifecycle supplies the actual node; traversal is metadata, never a write grant. */
    public function assertDestination(\OCP\Files\Folder $folder,bool $create):void {
        $this->access->clearCache(true);$this->access->assertScopeReadable();
        if(!$folder->isReadable()||($create?(!$folder->isCreatable()||!$this->access->isVisible($folder)):!$this->access->isTraversable($folder)))
            throw new \RuntimeException('Forms destination is outside the current Files access');
    }
    /** Caller supplies freshly reconstructed, identity-checked native copies and actual write parents. */
    public function assertTransferFiles(string $uid,array $manifest,array $copies,array $parents):void {
        if(!array_is_list($copies)||!array_is_list($parents)||count($copies)>1000||count($parents)>2000
            ||!is_array($manifest['files']??null)||!array_is_list($manifest['files'])||count($manifest['files'])>2000)
            throw new \RuntimeException('Forms transfer resource projection is invalid');
        foreach($copies as $copy)if(!$copy instanceof \OCP\Files\File||(int)$copy->getId()<1)throw new \RuntimeException('Forms transfer resource projection is invalid');
        foreach($parents as $parent)if(!$parent instanceof \OCP\Files\Folder||(int)$parent->getId()<1)throw new \RuntimeException('Forms transfer resource projection is invalid');
        if($manifest['files']===[]&&$copies===[]&&$parents===[])return;
        // Sources can include bounded distinct managed-share folder IDs in addition to answer files.
        foreach(array_chunk($manifest['files'],1000) as $page)$this->assertVisible($uid,['files'=>$page]);
        $this->access->clearCache(true);$this->access->assertScopeReadable();
        foreach($copies as $copy)if(!$copy->isReadable()||!$this->access->isVisible($copy))throw new \RuntimeException('Forms transfer copy is outside the current Files access');
        foreach($parents as $parent)$this->assertDestination($parent,true);
    }
    public function assertVisible(string $uid,array $manifest): void {
        $this->access->clearCache(true);$this->access->assertScopeReadable();
        $parents=[];
        if(isset($manifest['share_impact']['parent']))$parents[]=$manifest['share_impact']['parent'];
        foreach($manifest['share_removals']??[] as $removal) {
            if(!is_array($removal['parent']??null))throw new \RuntimeException('Forms managed share parent is unavailable.');
            $parents[]=$removal['parent'];
        }
        if(count($parents)>100)throw new \RuntimeException('Forms managed share parents exceed the bounded effect limit.');
        foreach($parents as $parent) {
            $allowed=false;
            foreach ($this->root->getUserFolder($uid)->getById((int)$parent['file_id']) as $node) {
                if ($node instanceof \OCP\Files\Folder && $node->isReadable() && $this->access->isVisible($node)
                    && (!($parent['create_folder']??false) || $node->isCreatable())) {$allowed=true;break;}
            }
            if (!$allowed) {throw new \RuntimeException('Forms file effect parent is outside the current Files access');}
        }
        $ids=[];
        foreach($manifest['files']??[] as $meta)$ids[(int)$meta['file_id']]=true;
        foreach([$manifest['share_impact']??[],...($manifest['share_removals']??[])] as $share) {
            foreach($share['folder_ids']??[] as $id)$ids[(int)$id]=true;
            foreach($share['managed_shares']??[] as $record) {
                foreach(['folder_id','actual_node_id'] as $key)if(isset($record[$key]))$ids[(int)$record[$key]]=true;
            }
        }
        if(count($ids)>1000||isset($ids[0]))throw new \RuntimeException('Forms managed share targets exceed the bounded effect limit.');
        foreach (array_keys($ids) as $id) {
            $allowed=false;
            foreach ($this->root->getUserFolder($uid)->getById($id) as $node) {
                if ($node->isReadable() && $this->access->isVisible($node)) {$allowed=true;break;}
            }
            if (!$allowed) {throw new \RuntimeException('Forms file effect is outside the current Files access');}
        }
    }
}
