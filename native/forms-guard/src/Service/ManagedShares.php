<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCP\{Server, IDBConnection};
use OCP\Files\{Folder, File};
use OCA\Forms\Db\{Form, Share, ShareMapper};
/** Records exact Files share identities created by native Forms; never adopts a user's matching share. */
final class ManagedShares
{
    public static function registerFolder(Form $form, Folder $folder): void
    {
        $db = Server::get(IDBConnection::class);
        if (!self::knownFolder($form->getId(), (int) $folder->getId())) {
            $db->executeStatement('INSERT INTO *PREFIX*forms_mcp_folders (form_id,folder_id) VALUES (?, ?)', [$form->getId(), $folder->getId()]);
        }
    }
    private static function knownFolder(int $formId, int $folderId): bool
    {
        return (bool) Server::get(IDBConnection::class)->executeQuery('SELECT folder_id FROM *PREFIX*forms_mcp_folders WHERE form_id = ? AND folder_id = ?', [$formId, $folderId])->fetchOne();
    }
    public static function snapshot(int $formId, array $files, array $input): array
    {
        $native = Server::get(ShareMapper::class)->findById((int) $input['share_id']);
        return self::snapshotNative($formId, $files, $input, $native);
    }
    private static function snapshotNative(int $formId, array $files, array $input, Share $native): array
    {
        if ($native->getFormId() !== $formId) {
            throw new \RuntimeException('Share is unavailable');
        }
        if (!in_array($native->getShareType(), [0, 1, 2, 7], true)) {
            return [];
        }
        SharingIdentity::assertType($native->getShareType());
        $form = Server::get(\OCA\Forms\Db\FormMapper::class)->findById($formId);
        $folders = Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getAllFormFoldersById($formId, $form->getOwnerId());
        $known = array_fill_keys(array_column($files, 'file_id'), true);
        $folderIds = [];
        $manager = Server::get(\OCP\Share\IManager::class);
        $managed = self::rows($formId, (int) $native->getId());
        $managedIds = array_column($managed, 'files_share_id');
        foreach ($managed as &$record) {
            if (self::isAbsent($record['files_share_id'])) {
                $record['missing'] = true;
                continue;
            }
            $actual = $manager->getShareById($record['files_share_id'], onlyValid: false);
            self::assertIdentity($actual, ['owner_id' => $form->getOwnerId(), 'recipient' => $native->getShareWith(), 'type' => $native->getShareType()], $record);
            $record['permissions'] = $actual->getPermissions();
            $record['recipient'] = $actual->getSharedWith();
            $record['type'] = $actual->getShareType();
            $record['owner'] = $actual->getShareOwner();
            $record['actual_node_id'] = $actual->getNodeId();
        }
        unset($record);
        foreach ($folders as $folder) {
            self::assertFolder($formId, $folder, $known);
            $folderIds[] = (int) $folder->getId();
            foreach ($manager->getSharesBy($form->getOwnerId(), $native->getShareType(), $folder, false, -1, 0, false) as $share) {
                if ($share->getSharedWith() === $native->getShareWith() && !in_array($share->getFullId(), $managedIds, true)) {
                    throw new \RuntimeException('Unmanaged Files share in native Forms effect path');
                }
            }
        }
        $root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($form->getOwnerId());
        $target = Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
        $parentPath = $root->nodeExists($target) ? $target : ($root->nodeExists('Forms') ? 'Forms' : '');
        $parent = $parentPath === '' ? $root : $root->get($parentPath);
        if (!$parent instanceof Folder) {
            throw new \RuntimeException('Native Forms share parent is not a folder');
        }
        return ['parent' => ['file_id' => (int) $parent->getId(), 'path' => $parent->getPath(), 'create_folder' => !$root->nodeExists($target)], 'native_share_id' => $native->getId(), 'type' => $native->getShareType(), 'recipient' => $native->getShareWith(), 'folder_ids' => $folderIds, 'managed_shares' => $managed, 'create' => isset($input['permissions']) && in_array('results', $input['permissions'], true), 'permissions' => ['read']];
    }
    /** Exact recorded IDs remain owned evidence even after a partial native unshare deleted its Forms row. */
    public static function removalShares(int $formId): array
    {
        $rows = Server::get(IDBConnection::class)->executeQuery('SELECT DISTINCT native_share_id FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ? ORDER BY native_share_id LIMIT 101', [$formId])->fetchAll();
        if (count($rows) > 100) {
            throw new \RuntimeException('Forms share removal count exceeds the bounded step');
        }
        $shares = [];
        foreach ($rows as $row) {
            $nativeId = (int) $row['native_share_id'];
            try {
                $native = Server::get(ShareMapper::class)->findById($nativeId);
            } catch (\OCP\AppFramework\Db\DoesNotExistException) {
                $native = self::historicalShare($formId, $nativeId);
            }
            if ($native->getFormId() !== $formId) {
                throw new \RuntimeException('Managed share form identity changed');
            }
            $shares[] = $native;
        }
        return $shares;
    }
    public static function historicalShare(int $formId, int $nativeId): Share
    {
        $owner = Server::get(\OCA\Forms\Db\FormMapper::class)->findById($formId)->getOwnerId();
        $rows = Server::get(IDBConnection::class)->executeQuery('SELECT intent_key FROM *PREFIX*forms_mcp_share_intents WHERE form_id = ? AND native_share_id = ? AND phase = ? ORDER BY intent_key LIMIT 101', [$formId, $nativeId, 'verified'])->fetchAll();
        foreach ($rows as $row) {
            $proof = EffectEnvelope::get($row['intent_key']);
            if (!$proof['create'] || $proof['owner_id'] !== $owner || $proof['form_id'] !== $formId || $proof['native_share_id'] !== $nativeId) {
                continue;
            }
            $native = new Share();
            $native->setId($nativeId);
            $native->setFormId($formId);
            $native->setShareType($proof['type']);
            $native->setShareWith($proof['recipient']);
            $native->setPermissions([]);
            return $native;
        }
        if (BulkStore::ready()) {
            // A transfer's sealed checkpoint owns exact newly-created share evidence even
            // after a later native unshare removed its Forms row but failed externally.
            $rows = Server::get(IDBConnection::class)->executeQuery("SELECT r.bulk_id,r.source_id,m.files_share_id,m.folder_id FROM *PREFIX*forms_mcp_bulk_rows r INNER JOIN *PREFIX*forms_mcp_file_shares m ON m.files_share_id = CONCAT('ocinternal:',r.mapped_id) WHERE r.kind = 'staged_shares' AND m.form_id = ? AND m.native_share_id = ? ORDER BY r.source_id LIMIT 1", [$formId,$nativeId])->fetchAll();
            foreach ($rows as $row) {
                $store=Server::get(BulkStore::class);$job=$store->load($row['bulk_id']);$proof=$store->one($row['bulk_id'],'staged_shares',(int)$row['source_id']);
                if($job['action']!=='forms_transfer'||$job['phase']!=='complete'||(int)$job['form']['id']!==$formId||$job['input']['owner_id']!==$owner||$proof['files_share_id']!==$row['files_share_id']||(int)$proof['folder_id']!==(int)$row['folder_id']||$proof['native_share_id']!==$nativeId||$proof['approved_tuple']['owner_id']!==$owner){throw new \RuntimeException('Transferred managed share evidence does not match');}
                $native=new Share();$native->setId($nativeId);$native->setFormId($formId);$native->setShareType($proof['approved_tuple']['type']);$native->setShareWith($proof['approved_tuple']['recipient']);$native->setPermissions([]);return $native;
            }
        }
        throw new \RuntimeException('Managed share historical identity is unavailable within the bounded lookup');
    }
    public static function removalSnapshots(int $formId, array $files): array
    {
        $impacts = [];
        foreach (self::removalShares($formId) as $native) {
            $impact = self::snapshotNative($formId, $files, ['share_id' => $native->getId()], $native);
            if (array_filter($impact['managed_shares'] ?? [], static fn(array $row): bool => !($row['missing'] ?? false)) !== []) {
                $impacts[] = $impact;
            }
        }
        return $impacts;
    }
    private static function assertFolder(int $id, Folder $folder, array $known): bool
    {
        $managed = self::knownFolder($id, (int) $folder->getId());
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File) {
                if (!isset($known[(int) $node->getId()])) {
                    throw new \RuntimeException('Unmanaged file in native Forms share path');
                }
                $managed = true;
            } elseif ($node instanceof Folder) {
                $managed = self::assertFolder($id, $node, $known) || $managed;
            } else {
                throw new \RuntimeException('Unsupported native Forms share node');
            }
        }
        if (!$managed) {
            throw new \RuntimeException('Unmanaged folder in native Forms share path');
        }
        return true;
    }
    public static function assertCurrentFolder(Form $form, Folder $folder): void
    {
        $rows = Server::get(IDBConnection::class)->executeQuery('SELECT a.file_id FROM *PREFIX*forms_v2_answers a INNER JOIN *PREFIX*forms_v2_submissions s ON a.submission_id = s.id WHERE s.form_id = ? AND a.file_id IS NOT NULL LIMIT 101', [$form->getId()])->fetchAll();
        if (count($rows) > 100) {
            throw new \RuntimeException('Forms share files exceed budget');
        }
        self::assertFolder($form->getId(), $folder, array_fill_keys(array_column($rows, 'file_id'), true));
    }
    public static function assertIdentity(\OCP\Share\IShare $share, array $intent, array $row): void
    {
        if ($share->getFullId() !== $row['files_share_id'] || $share->getShareOwner() !== $intent['owner_id'] || (int) $share->getNodeId() !== (int) $row['folder_id'] || $share->getSharedWith() !== $intent['recipient'] || $share->getShareType() !== $intent['type'] || $share->getPermissions() !== 1) {
            throw new \RuntimeException('Native Files share identity changed');
        }
    }
    public static function rows(int $formId, int $nativeId): array
    {
        $rows = Server::get(IDBConnection::class)->executeQuery('SELECT * FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ? AND native_share_id = ? ORDER BY files_share_id LIMIT 101', [$formId, $nativeId])->fetchAll();
        if (count($rows) > 100) {
            throw new \RuntimeException('Managed share count exceeds the bounded step');
        }
        return $rows;
    }
    /** Mutating cleanup is limited to exact recorded share IDs independently proven absent. */
    public static function isAbsent(string $fullId): bool
    {
        SharingIdentity::assertType(0);
        if (!preg_match('/^ocinternal:([1-9][0-9]*)$/D', $fullId, $matches) || ($id = filter_var($matches[1], FILTER_VALIDATE_INT)) === false || $id < 1) {
            throw new \RuntimeException('Managed share provider identity is unqualified');
        }
        return Server::get(IDBConnection::class)->executeQuery('SELECT id FROM *PREFIX*share WHERE id = ?', [$id])->fetchOne() === false;
    }
    public static function existingRows(int $formId, int $nativeId): array
    {
        $live = [];
        foreach (self::rows($formId, $nativeId) as $row) {
            if (self::isAbsent($row['files_share_id'])) {
                Server::get(IDBConnection::class)->executeStatement('DELETE FROM *PREFIX*forms_mcp_file_shares WHERE files_share_id = ?', [$row['files_share_id']]);
            } else {
                $live[] = $row;
            }
        }
        return $live;
    }
    public static function remove(Form $form, Share $native): void
    {
        ManagedSharePhase::schedule($form, $native, false);
    }
    public static function verify(int $formId, array $shareImpact): void
    {
        if ($shareImpact === [] || Server::get(ManagedSharePhase::class)->hasPending($formId, (int) $shareImpact['native_share_id'])) {
            return;
        }
        $rows = self::rows($formId, (int) $shareImpact['native_share_id']);
        if ($shareImpact['create'] && $rows === []) {
            throw new \RuntimeException('Native Files share creation was not verified');
        }
        if (!$shareImpact['create'] && $rows !== []) {
            throw new \RuntimeException('Native Files share removal was not verified');
        }
        foreach ($rows as $row) {
            $share = Server::get(\OCP\Share\IManager::class)->getShareById($row['files_share_id'], onlyValid: false);
            if ($share->getSharedWith() !== $shareImpact['recipient'] || $share->getPermissions() !== 1) {
                throw new \RuntimeException('Native Files share outcome mismatch');
            }
        }
    }
}
