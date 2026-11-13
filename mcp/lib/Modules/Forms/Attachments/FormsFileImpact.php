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
    public function assertVisible(string $uid,array $manifest): void {
        $this->access->clearCache();$this->access->assertScopeReadable();
        $parent=$manifest['share_impact']['parent']??null;
        if (is_array($parent)) {
            $allowed=false;
            foreach ($this->root->getUserFolder($uid)->getById((int)$parent['file_id']) as $node) {
                if ($node instanceof \OCP\Files\Folder && $node->isReadable() && $this->access->isVisible($node)
                    && (!($parent['create_folder']??false) || $node->isCreatable())) {$allowed=true;break;}
            }
            if (!$allowed) {throw new \RuntimeException('Forms file effect parent is outside the current Files access');}
        }
        foreach ($manifest['files']??[] as $meta) {
            $allowed=false;
            foreach ($this->root->getUserFolder($uid)->getById((int)$meta['file_id']) as $node) {
                if ($node->isReadable() && $this->access->isVisible($node)) {$allowed=true;break;}
            }
            if (!$allowed) {throw new \RuntimeException('Forms file effect is outside the current Files access');}
        }
    }
}
