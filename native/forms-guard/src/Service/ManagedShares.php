<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{Server,IDBConnection};
use OCP\Files\{Folder,File};
use OCA\Forms\Db\{Form,Share,ShareMapper};
/** Records exact Files share identities created by native Forms; never adopts a user's matching share. */
final class ManagedShares {
    public static function registerFolder(Form $form,Folder $folder): void {
        $db=Server::get(IDBConnection::class);
        if (!self::knownFolder($form->getId(),(int)$folder->getId())) {$db->executeStatement('INSERT INTO *PREFIX*forms_mcp_folders (form_id,folder_id) VALUES (?, ?)',[$form->getId(),$folder->getId()]);}
    }
    private static function knownFolder(int $formId,int $folderId): bool {
        return (bool)Server::get(IDBConnection::class)->executeQuery('SELECT folder_id FROM *PREFIX*forms_mcp_folders WHERE form_id = ? AND folder_id = ?',[$formId,$folderId])->fetchOne();
    }
    public static function snapshot(int $formId,array $files,array $input): array {
        $native=Server::get(ShareMapper::class)->findById((int)$input['share_id']);
        if ($native->getFormId()!==$formId) {throw new \RuntimeException('Share is unavailable');}
        if (!in_array($native->getShareType(),[0,1,2,7],true)) {return [];}
        if (Server::get(IDBConnection::class)->getDatabaseProvider(true)==='postgres' && (in_array('results',$input['permissions']??[],true) || in_array('results',$native->getPermissions(),true))) {throw new \RuntimeException('Native Files sharing is not qualified on PostgreSQL');}
        $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($formId);
        $folders=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getAllFormFoldersById($formId,$form->getOwnerId());$known=array_fill_keys(array_column($files,'file_id'),true);$folderIds=[];
        $manager=Server::get(\OCP\Share\IManager::class);$managed=self::rows($formId,(int)$native->getId());$managedIds=array_column($managed,'files_share_id');
        foreach ($managed as &$record) {$actual=$manager->getShareById($record['files_share_id']);$record['permissions']=$actual->getPermissions();$record['recipient']=$actual->getSharedWith();$record['type']=$actual->getShareType();$record['owner']=$actual->getShareOwner();$record['actual_node_id']=$actual->getNodeId();}unset($record);
        foreach ($folders as $folder) {
            self::assertFolder($formId,$folder,$known);$folderIds[]=(int)$folder->getId();
            foreach ($manager->getSharesBy($form->getOwnerId(),$native->getShareType(),$folder,false,-1) as $share) {
                if ($share->getSharedWith()===$native->getShareWith() && !in_array($share->getFullId(),$managedIds,true)) {throw new \RuntimeException('Unmanaged Files share in native Forms effect path');}
            }
        }
        $root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($form->getOwnerId());
        $target=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
        $parentPath=$root->nodeExists($target)?$target:($root->nodeExists('Forms')?'Forms':'');
        $parent=$parentPath===''?$root:$root->get($parentPath);
        if (!$parent instanceof Folder) {throw new \RuntimeException('Native Forms share parent is not a folder');}
        return ['parent'=>['file_id'=>(int)$parent->getId(),'path'=>$parent->getPath(),'create_folder'=>!$root->nodeExists($target)],'native_share_id'=>$native->getId(),'type'=>$native->getShareType(),'recipient'=>$native->getShareWith(),'folder_ids'=>$folderIds,'managed_shares'=>$managed,
            'create'=>isset($input['permissions'])&&in_array('results',$input['permissions'],true),'permissions'=>['read']];
    }
    private static function assertFolder(int $id,Folder $folder,array $known): bool {
        $managed=self::knownFolder($id,(int)$folder->getId());
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File) {if (!isset($known[(int)$node->getId()])) {throw new \RuntimeException('Unmanaged file in native Forms share path');}$managed=true;}
            elseif ($node instanceof Folder) {$managed=self::assertFolder($id,$node,$known)||$managed;}
            else {throw new \RuntimeException('Unsupported native Forms share node');}
        }
        if (!$managed) {throw new \RuntimeException('Unmanaged folder in native Forms share path');}
        return true;
    }
    public static function create(Form $form,Share $native,\OCP\Share\IShare $share): \OCP\Share\IShare {
        $folder=$share->getNode();
        if (!$folder instanceof Folder) {throw new \RuntimeException('Forms share target is not a folder');}
        $locked=false;$failure=null;
        try {
            $folder->lock(\OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);$locked=true;
            // Re-enumerate while a folder lock prevents concurrent child writes. Native IDs are the ownership evidence.
            $db=Server::get(IDBConnection::class);$rows=$db->executeQuery('SELECT a.file_id FROM *PREFIX*forms_v2_answers a INNER JOIN *PREFIX*forms_v2_submissions s ON a.submission_id = s.id WHERE s.form_id = ?',[$form->getId()])->fetchAll();
            self::assertFolder($form->getId(),$folder,array_fill_keys(array_column($rows,'file_id'),true));
            $created=Server::get(\OCP\Share\IManager::class)->createShare($share);
            $db->executeStatement('INSERT INTO *PREFIX*forms_mcp_file_shares (files_share_id,form_id,native_share_id,folder_id) VALUES (?, ?, ?, ?)',[$created->getFullId(),$form->getId(),$native->getId(),$folder->getId()]);
            self::registerFolder($form,$folder);return $created;
        } catch (\Throwable $e) {$failure=$e;throw $e;}
        finally {if ($locked) {try {$folder->unlock(\OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);} catch (\Throwable $unlock) {if ($failure===null) {throw $unlock;}}}}
    }
    private static function rows(int $formId,int $nativeId): array {
        return Server::get(IDBConnection::class)->executeQuery('SELECT * FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ? AND native_share_id = ? ORDER BY files_share_id',[$formId,$nativeId])->fetchAll();
    }
    public static function remove(Form $form,Share $native): void {
        $manager=Server::get(\OCP\Share\IManager::class);$db=Server::get(IDBConnection::class);
        foreach (self::rows($form->getId(),$native->getId()) as $row) {
            $share=$manager->getShareById($row['files_share_id']);
            if ($share->getShareOwner()!==$form->getOwnerId() || (int)$share->getNodeId()!==(int)$row['folder_id'] || $share->getSharedWith()!==$native->getShareWith() || $share->getShareType()!==$native->getShareType()) {throw new \RuntimeException('Native Files share identity changed');}
            $manager->deleteShare($share);$db->executeStatement('DELETE FROM *PREFIX*forms_mcp_file_shares WHERE files_share_id = ?',[$row['files_share_id']]);
        }
    }
    public static function verify(int $formId,array $shareImpact): void {
        if ($shareImpact===[]) {return;}
        $rows=self::rows($formId,(int)$shareImpact['native_share_id']);
        if ($shareImpact['create'] && $rows===[]) {throw new \RuntimeException('Native Files share creation was not verified');}
        if (!$shareImpact['create'] && $rows!==[]) {throw new \RuntimeException('Native Files share removal was not verified');}
        foreach ($rows as $row) {$share=Server::get(\OCP\Share\IManager::class)->getShareById($row['files_share_id']);if ($share->getSharedWith()!==$shareImpact['recipient'] || $share->getPermissions()!==1) {throw new \RuntimeException('Native Files share outcome mismatch');}}
    }
}
