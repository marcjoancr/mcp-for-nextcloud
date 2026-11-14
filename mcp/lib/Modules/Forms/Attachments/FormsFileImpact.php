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
        $this->access->clearCache();$this->access->assertScopeReadable();
        if(!$folder->isReadable()||($create?(!$folder->isCreatable()||!$this->access->isVisible($folder)):!$this->access->isTraversable($folder)))
            throw new \RuntimeException('Forms destination is outside the current Files access');
    }
    public function assertVisible(string $uid,array $manifest): void {
        $this->access->clearCache();$this->access->assertScopeReadable();
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
