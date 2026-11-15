<?php
/* UNAPPLIED R2 proposal. Native source: Model/FileCacheWrapper.php SHA256 c73539d8feb9d07045185c289e11c7870cc24165d84bd8a7a7abab536159c2fd
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

final class FileCacheWrapper extends \OCA\Circles\Model\FileCacheWrapper
{
    use \OCA\Circles\Tools\Traits\TArrayTools;
    private int $id = 0;
    private string $path = '';
    private int $permissions = 0;
    private int $storageId = 0;
    private string $pathHash = '';
    private int $parent = 0;
    private string $name = '';
    private int $mimeType = 0;
    private int $mimePart = 0;
    private int $size = 0;
    private int $mTime = 0;
    private int $storageMTime = 0;
    private bool $encrypted = false;
    private int $unencryptedSize = 0;
    private string $etag = '';
    private string $checksum = '';
    private string $storage = '';
    public function setId(int $id): self
    {
        $this->__formsCircleLease->assertActive();
        $this->id = $id;
        return $this;
    }
    public function getId(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->id;
    }
    public function setPath(string $path): self
    {
        $this->__formsCircleLease->assertActive();
        $this->path = $path;
        return $this;
    }
    public function getPath(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->path;
    }
    public function setPathHash(string $pathHash): self
    {
        $this->__formsCircleLease->assertActive();
        $this->pathHash = $pathHash;
        return $this;
    }
    public function getPathHash(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->pathHash;
    }
    public function setName(string $name): self
    {
        $this->__formsCircleLease->assertActive();
        $this->name = $name;
        return $this;
    }
    public function getName(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->name;
    }
    public function setStorageMTime(int $storageMTime): self
    {
        $this->__formsCircleLease->assertActive();
        $this->storageMTime = $storageMTime;
        return $this;
    }
    public function getStorageMTime(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->storageMTime;
    }
    public function setEncrypted(bool $encrypted): self
    {
        $this->__formsCircleLease->assertActive();
        $this->encrypted = $encrypted;
        return $this;
    }
    public function isEncrypted(): bool
    {
        $this->__formsCircleLease->assertActive();
        return $this->encrypted;
    }
    public function setUnencryptedSize(int $unencryptedSize): self
    {
        $this->__formsCircleLease->assertActive();
        $this->unencryptedSize = $unencryptedSize;
        return $this;
    }
    public function getUnencryptedSize(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->unencryptedSize;
    }
    public function setEtag(string $etag): self
    {
        $this->__formsCircleLease->assertActive();
        $this->etag = $etag;
        return $this;
    }
    public function getEtag(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->etag;
    }
    public function setChecksum(string $checksum): self
    {
        $this->__formsCircleLease->assertActive();
        $this->checksum = $checksum;
        return $this;
    }
    public function getChecksum(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->checksum;
    }
    public function setSize(int $size): self
    {
        $this->__formsCircleLease->assertActive();
        $this->size = $size;
        return $this;
    }
    public function getSize(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->size;
    }
    public function setMTime(int $mTime): self
    {
        $this->__formsCircleLease->assertActive();
        $this->mTime = $mTime;
        return $this;
    }
    public function getMTime(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->mTime;
    }
    public function setMimeType(int $mimeType): self
    {
        $this->__formsCircleLease->assertActive();
        $this->mimeType = $mimeType;
        return $this;
    }
    public function getMimeType(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->mimeType;
    }
    public function setMimePart(int $mimePart): self
    {
        $this->__formsCircleLease->assertActive();
        $this->mimePart = $mimePart;
        return $this;
    }
    public function getMimePart(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->mimePart;
    }
    public function setStorageId(int $storageId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->storageId = $storageId;
        return $this;
    }
    public function getStorageId(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->storageId;
    }
    public function setStorage(string $storage): self
    {
        $this->__formsCircleLease->assertActive();
        $this->storage = $storage;
        return $this;
    }
    public function getStorage(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->storage;
    }
    public function setPermissions(int $permissions): self
    {
        $this->__formsCircleLease->assertActive();
        $this->permissions = $permissions;
        return $this;
    }
    public function getPermissions(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->permissions;
    }
    public function setParent(int $parent): self
    {
        $this->__formsCircleLease->assertActive();
        $this->parent = $parent;
        return $this;
    }
    public function getParent(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->parent;
    }
    public function toCache(): array
    {
        $this->__formsCircleLease->assertActive();
        return [
            'fileid' => $this->getId(),
            'path' => $this->getPath(),
            'permissions' => $this->getPermissions(),
            'storage' => $this->getStorageId(),
            // strange, it is not !?
            'path_hash' => $this->getPathHash(),
            'parent' => $this->getParent(),
            'name' => $this->getName(),
            'mimetype' => $this->getMimeType(),
            'mimepart' => $this->getMimePart(),
            'size' => $this->getSize(),
            'mtime' => $this->getMTime(),
            'storage_mtime' => $this->getStorageMTime(),
            'encrypted' => $this->isEncrypted(),
            'unencrypted_size' => $this->getUnencryptedSize(),
            'etag' => $this->getEtag(),
            'checksum' => $this->getChecksum(),
        ];
    }
    /**
     * Returns whether the given database result can be interpreted as
     * a share with accessible file (not trashed, not deleted)
     *
     * @return bool
     */
    public function isAccessible(): bool
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getId() === 0) {
            return false;
        }
        $path = $this->getPath();
        [$storageType] = explode('::', $this->getStorage(), 2);
        if ($path === '') {
            // we only accept empty path on external storage
            return in_array($storageType, ['local', 'webdav', 'ftp', 'sftp', 'swift', 'smb', 'amazon']);
        }
        // on home storages and on group folders with a separate storage, only files/ is accessible
        $storage = $this->getStorage();
        if (explode('/', $path, 2)[0] !== 'files' && (str_starts_with($storage, 'home::') || str_starts_with($storage, 'object::user') || str_starts_with($storage, 'object::groupfolder:') || preg_match('#^local::.*/__groupfolders/\d+/$#', $storage) === 1)) {
            return false;
        }
        // group folders stored in the root storage have their trashbin in __groupfolders/trash/
        return !str_starts_with($path, '__groupfolders/trash/');
    }
    /**
     * @param array $data
     *
     * @return self
     * @throws InvalidItemException
     */
    public function import(array $data): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getInt('id', $data) === 0) {
            throw new \OCA\Circles\Tools\Exceptions\InvalidItemException();
        }
        $this->setId($this->getInt('id', $data))->setPath($this->get('path', $data))->setPermissions($this->getInt('permissions', $data))->setStorage($this->get('storage', $data))->setStorageId($this->getInt('storageId', $data))->setPathHash($this->get('pathHash', $data))->setParent($this->getInt('parent', $data))->setName($this->get('name', $data))->setMimeType($this->getInt('mimeType', $data))->setMimePart($this->getInt('mimePart', $data))->setSize($this->getInt('size', $data))->setMTime($this->getInt('mTime', $data))->setStorageMTime($this->getInt('storageMTime', $data))->setEncrypted($this->getBool('encrypted', $data))->setUnencryptedSize($this->getInt('unencryptedSize', $data))->setEtag($this->get('etag', $data))->setChecksum($this->get('checksum', $data));
        return $this;
    }
    /**
     * @param array $data
     * @param string $prefix
     *
     * @return IQueryRow
     * @throws FileCacheNotFoundException
     */
    public function importFromDatabase(array $data, string $prefix = ''): \OCA\Circles\Tools\Db\IQueryRow
    {
        $this->__formsCircleLease->assertActive();
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        if ($this->getInt($prefix . 'fileid', $data) === 0) {
            throw new \OCA\Circles\Exceptions\FileCacheNotFoundException();
        }
        $this->setId($this->getInt($prefix . 'fileid', $data));
        $this->setPath($this->get($prefix . 'path', $data));
        $this->setPermissions($this->getInt($prefix . 'permissions', $data));
        $this->setStorageId($this->getInt($prefix . 'storage', $data));
        $this->setPathHash($this->get($prefix . 'path_hash', $data));
        $this->setParent($this->getInt($prefix . 'parent', $data));
        $this->setName($this->get($prefix . 'name', $data));
        $this->setMimeType($this->getInt($prefix . 'mimetype', $data));
        $this->setMimePart($this->getInt($prefix . 'mimepart', $data));
        $this->setSize($this->getInt($prefix . 'size', $data));
        $this->setMTime($this->getInt($prefix . 'mtime', $data));
        $this->setStorageMTime($this->getInt($prefix . 'storage_mtime', $data));
        $this->setEncrypted($this->getBool($prefix . 'encrypted', $data));
        $this->setUnencryptedSize($this->getInt($prefix . 'unencrypted_size', $data));
        $this->setEtag($this->get($prefix . 'etag', $data));
        $this->setChecksum($this->get($prefix . 'checksum', $data));
        // small hack as there is no reason to call a recursive method for a single entry from the table
        $this->setStorage($this->get($prefix . \OCA\Circles\Db\CoreQueryBuilder::STORAGES . '_id', $data));
        return $this;
    }
    public function jsonSerialize(): array
    {
        $this->__formsCircleLease->assertActive();
        return ['id' => $this->getId(), 'path' => $this->getPath(), 'permissions' => $this->getPermissions(), 'storage' => $this->getStorage(), 'storageId' => $this->getStorageId(), 'pathHash' => $this->getPathHash(), 'parent' => $this->getParent(), 'name' => $this->getName(), 'mimeType' => $this->getMimeType(), 'mimePart' => $this->getMimePart(), 'size' => $this->getSize(), 'mTime' => $this->getMTime(), 'storageMTime' => $this->getStorageMTime(), 'encrypted' => $this->isEncrypted(), 'unencryptedSize' => $this->getUnencryptedSize(), 'etag' => $this->getEtag(), 'checksum' => $this->getChecksum()];
    }
    private Lease $__formsCircleLease;
    final public function bindCircleLease(Lease $lease): void
    {
        if (isset($this->__formsCircleLease)) {
            throw new \LogicException("Circle model is already bound");
        }
        $this->__formsCircleLease = $lease;
    }
    protected function getManager(): \OCA\Circles\Model\ModelManager
    {
        $this->__formsCircleLease->assertActive();
        return $this->__formsCircleLease->manager();
    }
protected function getList(string $k,array $arr,array $import,array $default=[]): array {
    $this->__formsCircleLease->assertActive();
    if(count($import)!==2||!is_string($import[0])||$import[1]!=='import'){throw new \RuntimeException('Circle import method is not qualified');}
    $rows=$this->getArray($k,$arr,$default);
    \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate($rows);
    $out=[];foreach($rows as $row){$model=$this->__formsCircleLease->model($import[0]);$model->import($row);$out[]=$model;}return $out;
}
}
