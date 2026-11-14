<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

/** Evidence from the exact native INSERT path, distinct from Manager reusing a concurrent existing share. */
final class SharingDefaultProvider extends \OC\Share20\DefaultShareProvider
{
    private array $created = [];
    private bool $transferQuarantine = false;
    public function quarantineTransferCreates():void {$this->transferQuarantine=true;}
    private ?\Closure $beforeWrite = null;
    public function setWriteBoundary(\Closure $beforeWrite): void
    {
        $this->beforeWrite = $beforeWrite;
    }
    private function authorizeWrite(\OCP\Share\IShare $share, bool $create): void
    {
        if ($this->beforeWrite === null) {
            throw new \RuntimeException('Native managed share write boundary is unavailable');
        }
        ($this->beforeWrite)($share, $create);
    }
    public function delete(\OCP\Share\IShare $share)
    {
        $this->authorizeWrite($share, false);
        return parent::delete($share);
    }
    public function create(\OCP\Share\IShare $share)
    {
        $this->authorizeWrite($share, true);
        if($this->transferQuarantine){if($share->getPermissions()!==1){throw new \RuntimeException('Expected approved READ transfer intent');}$share->setPermissions(0);$this->authorizeWrite($share,true);}
        $result = parent::create($share);
        $this->created[$result->getFullId()] = true;
        return $result;
    }
    /** Preserve existing recipient decisions during an explicit Forms ownership transfer. */
    public function preserveTransferRecipients(\OCP\Share\IShare $share,array $rows):array
    {
        if(count($rows)>100){throw new \RuntimeException('Recipient-state page exceeds budget');}
        $ids=[];foreach($rows as$row){
            if($share->getShareType()!==1||!in_array((int)$row['permissions'],[0,1],true)){throw new \RuntimeException('Unsupported group-recipient override');}
            $this->authorizeWrite($share,true);
            $core=new FreshCore($share->getSharedBy());$group=$core->groups()->get($share->getSharedWith());$user=\OCP\Server::get(\OCP\IUserManager::class)->get($row['share_with']);
            if($group===null||$user===null||!$group->inGroup($user)){throw new \RuntimeException('Recipient is no longer in the approved group');}
            $this->authorizeWrite($share,true);if($share->getPermissions()!==0){throw new \RuntimeException('Recipient staging requires quarantined parent');}
            if(\OCP\Server::get(\OCP\IDBConnection::class)->executeQuery('SELECT id FROM *PREFIX*share WHERE parent = ? AND share_type = 2 AND share_with = ?',[(int)$share->getId(),$row['share_with']])->fetchOne()!==false){throw new \RuntimeException('Unapproved or duplicate transfer recipient row');}
            $id=parent::createUserSpecificGroupShare($share,$row['share_with']);
            $this->authorizeWrite($share,true);
            \OCP\Server::get(\OCP\IDBConnection::class)->executeStatement('UPDATE *PREFIX*share SET accepted = ?,permissions = ?,expiration = ?,attributes = ? WHERE id = ? AND parent = ? AND share_type = 2 AND share_with = ?',[(int)$row['accepted'],0,$row['expiration'],$row['attributes'],$id,(int)$share->getId(),$row['share_with']]);$ids[]=$id;
        }return $ids;
    }
    /** All native update policy checks ran on the actual intended READ share. No post-update hooks run inside the final SQL commit. */
    public function activateTransfer(\OCP\Share\IShare $share):void {
        if($share->getPermissions()!==1){throw new \RuntimeException('Invalid transfer activation permissions');}$this->authorizeWrite($share,true);parent::update($share);
    }
    /** One bounded, locked SQL batch; no provider callback or external wait between rows. */
    public function activateTransferRecipients(\OCP\Share\IShare $share,array $rows,callable $authorize):void {
        if(count($rows)>100||strlen(json_encode($rows,JSON_THROW_ON_ERROR))>1048576){throw new \RuntimeException('Recipient activation batch exceeds budget');}
        $this->authorizeWrite($share,true);
        foreach($rows as$row){BulkDeadline::check();$source=$row['source'];$authorize($source);if($share->getPermissions()!==1||$share->getShareType()!==1||!in_array((int)$source['permissions'],[0,1],true)){throw new \RuntimeException('Invalid recipient activation');}
            $count=\OCP\Server::get(\OCP\IDBConnection::class)->executeStatement('UPDATE *PREFIX*share SET accepted = ?,permissions = ?,expiration = ?,attributes = ? WHERE id = ? AND parent = ? AND share_type = 2 AND share_with = ? AND uid_owner = ? AND uid_initiator = ? AND file_source = ? AND permissions = 0',[(int)$source['accepted'],(int)$source['permissions'],$row['expiration'],$source['attributes'],$row['id'],(int)$share->getId(),$source['share_with'],$share->getShareOwner(),$share->getSharedBy(),(int)$share->getNodeId()]);if($count!==1){throw new \RuntimeException('Recipient activation identity changed');}
        }
        $this->authorizeWrite($share,true);BulkDeadline::check();
    }
    public function createdHere(string $id): bool
    {
        return isset($this->created[$id]);
    }
}
