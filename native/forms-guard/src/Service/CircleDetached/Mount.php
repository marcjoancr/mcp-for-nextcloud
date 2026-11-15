<?php
/* UNAPPLIED R2 proposal. Native source: Model/Mount.php SHA256 3d0377f3f422d6b104c317418939826990d02adc972fe8ff5792121358f54983
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class Mount
 *
 * @package OCA\Circles\Model
 */
final class Mount extends \OCA\Circles\Model\Mount
{
    use \OCA\Circles\Tools\Traits\TArrayTools;
    private int $id = 0;
    private string $mountId = '';
    private string $circleId = '';
    private \OCA\Circles\Model\Member $owner;
    private \OCA\Circles\Model\Member $initiator;
    private int $parent = -1;
    private string $token = '';
    private string $password = '';
    private string $originalMountPoint = '';
    private string $originalMountPointHash = '';
    private ?\OCA\Circles\Model\Mountpoint $alternateMountPoint = null;
    private string $storage;
    private \OCP\Federation\ICloudIdManager $cloudIdManager;
    private \OCP\Http\Client\IClientService $httpClientService;
    private \OCA\Files_Sharing\External\Manager $externalShareManager;
    private string $remote = '';
    private int $remoteShareId = 0;
    /**
     * Mount constructor.
     */
    public function __construct()
    {
    }
    /**
     * @return int
     */
    public function getId(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->id;
    }
    public function setId(int $id): self
    {
        $this->__formsCircleLease->assertActive();
        $this->id = $id;
        return $this;
    }
    /**
     *
     * @return string
     */
    public function getMountId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->mountId;
    }
    /**
     * @param string $mountId
     *
     * @return Mount
     */
    public function setMountId(string $mountId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->mountId = $mountId;
        return $this;
    }
    /**
     *
     * @return string
     */
    public function getCircleId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->circleId;
    }
    /**
     * @param string $circleId
     *
     * @return Mount
     */
    public function setCircleId(string $circleId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->circleId = $circleId;
        return $this;
    }
    /**
     * @param bool $raw
     *
     * @return string
     */
    public function getMountPoint(bool $raw = true): string
    {
        $this->__formsCircleLease->assertActive();
        $mountPoint = $this->getAlternateMountPoint()?->getMountPoint() ?? $this->getOriginalMountPoint();
        if ($raw) {
            return $mountPoint;
        }
        return '/' . $this->getInitiator()->getUserId() . '/files/' . ltrim($mountPoint, '/');
    }
    /**
     * @return string
     */
    public function getOriginalMountPoint(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->originalMountPoint;
    }
    /**
     * @param string $originalMountPoint
     *
     * @return Mount
     */
    public function setOriginalMountPoint(string $originalMountPoint): self
    {
        $this->__formsCircleLease->assertActive();
        $this->originalMountPoint = $originalMountPoint;
        return $this;
    }
    /**
     * @return string
     */
    public function getOriginalMountPointHash(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->originalMountPointHash;
    }
    /**
     * @param string $originalMountPointHash
     *
     * @return Mount
     */
    public function setOriginalMountPointHash(string $originalMountPointHash): self
    {
        $this->__formsCircleLease->assertActive();
        $this->originalMountPointHash = $originalMountPointHash;
        return $this;
    }
    public function setAlternateMountPoint(\OCA\Circles\Model\Mountpoint $mountPoint): self
    {
        $this->__formsCircleLease->assertActive();
        $this->alternateMountPoint = $mountPoint;
        return $this;
    }
    public function getAlternateMountPoint(): ?\OCA\Circles\Model\Mountpoint
    {
        $this->__formsCircleLease->assertActive();
        return $this->alternateMountPoint;
    }
    /**
     * @return int
     */
    public function getParent(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->parent;
    }
    /**
     * @param int $parent
     *
     * @return Mount
     */
    public function setParent(int $parent): self
    {
        $this->__formsCircleLease->assertActive();
        $this->parent = $parent;
        return $this;
    }
    /**
     * @return Member
     */
    public function getOwner(): \OCA\Circles\Model\Member
    {
        $this->__formsCircleLease->assertActive();
        return $this->owner;
    }
    /**
     * @param Member $owner
     *
     * @return Mount
     */
    public function setOwner(\OCA\Circles\Model\Member $owner): self
    {
        $this->__formsCircleLease->assertActive();
        $this->owner = $owner;
        return $this;
    }
    /**
     * @return bool
     */
    public function hasInitiator(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->initiator);
    }
    /**
     * @return Member
     */
    public function getInitiator(): \OCA\Circles\Model\Member
    {
        $this->__formsCircleLease->assertActive();
        return $this->initiator;
    }
    /**
     * @param Member $initiator
     *
     * @return Mount
     */
    public function setInitiator(\OCA\Circles\Model\Member $initiator): self
    {
        $this->__formsCircleLease->assertActive();
        $this->initiator = $initiator;
        return $this;
    }
    /**
     * @return string
     */
    public function getToken(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->token;
    }
    /**
     * @param string $token
     *
     * @return Mount
     */
    public function setToken(string $token): self
    {
        $this->__formsCircleLease->assertActive();
        $this->token = $token;
        return $this;
    }
    /**
     * @return string
     */
    public function getPassword(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->password;
    }
    /**
     * @param string $password
     *
     * @return Mount
     */
    public function setPassword(string $password): self
    {
        $this->__formsCircleLease->assertActive();
        $this->password = $password;
        return $this;
    }
    /**
     * @param ICloudIdManager $cloudIdManager
     *
     * @return Mount
     */
    public function setCloudIdManager(\OCP\Federation\ICloudIdManager $cloudIdManager): self
    {
        $this->__formsCircleLease->assertActive();
        $this->cloudIdManager = $cloudIdManager;
        return $this;
    }
    /**
     * @return ICloudIdManager
     */
    public function getCloudIdManager(): \OCP\Federation\ICloudIdManager
    {
        $this->__formsCircleLease->assertActive();
        return $this->cloudIdManager;
    }
    /**
     * @param IClientService $httpClientService
     *
     * @return Mount
     */
    public function setHttpClientService(\OCP\Http\Client\IClientService $httpClientService): self
    {
        $this->__formsCircleLease->assertActive();
        $this->httpClientService = $httpClientService;
        return $this;
    }
    /**
     * @return IClientService
     */
    public function getHttpClientService(): \OCP\Http\Client\IClientService
    {
        $this->__formsCircleLease->assertActive();
        return $this->httpClientService;
    }
    public function setExternalShareManager(\OCA\Files_Sharing\External\Manager $externalShareManager): self
    {
        $this->__formsCircleLease->assertActive();
        $this->externalShareManager = $externalShareManager;
        return $this;
    }
    public function getExternalShareManager(): \OCA\Files_Sharing\External\Manager
    {
        $this->__formsCircleLease->assertActive();
        return $this->externalShareManager;
    }
    public function setRemote(string $remote): void
    {
        $this->__formsCircleLease->assertActive();
        $this->remote = $remote;
    }
    public function getRemote(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->remote;
    }
    public function setRemoteShareId(int $remoteShareId): void
    {
        $this->__formsCircleLease->assertActive();
        $this->remoteShareId = $remoteShareId;
    }
    public function getRemoteShareId(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->remoteShareId;
    }
    /**
     * @return array
     */
    public function toMount(): array
    {
        $this->__formsCircleLease->assertActive();
        $member = $this->getOwner();
        return ['owner' => $member->getUserId(), 'remote' => $member->getRemoteInstance()->getRoot(), 'token' => $this->getToken(), 'password' => $this->getPassword(), 'mountpoint' => $this->getMountPoint(false), 'HttpClientService' => $this->getHttpClientService(), 'manager' => $this->getExternalShareManager(), 'cloudId' => $this->getCloudIdManager()->getCloudId($member->getUserId(), $member->getRemoteInstance()->getRoot())];
    }
    /**
     * @param ShareWrapper $wrappedShare
     *
     * @throws CircleNotFoundException
     */
    public function fromShare(\OCA\Circles\Model\ShareWrapper $wrappedShare)
    {
        $this->__formsCircleLease->assertActive();
        if (!$wrappedShare->hasCircle()) {
            throw new \OCA\Circles\Exceptions\CircleNotFoundException('ShareWrapper has no Team');
        }
        $circle = $wrappedShare->getCircle();
        $this->setCircleId($circle->getSingleId());
        $this->setOwner($wrappedShare->getOwner());
        $this->setToken($wrappedShare->getToken());
        $this->setParent(-1);
        $this->setOriginalMountPoint($wrappedShare->getFileTarget());
        $this->setOriginalMountPointHash(md5($wrappedShare->getFileTarget()));
        $this->setRemote($wrappedShare->getOwner()->getInstance());
        $this->setRemoteShareId((int) $wrappedShare->getId());
    }
    /**
     * @param array $data
     *
     * @return IDeserializable
     */
    public function import(array $data): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        return $this;
    }
    /**
     * @param array $data
     * @param string $prefix
     *
     * @return Mount
     */
    public function importFromDatabase(array $data, string $prefix = ''): \OCA\Circles\Tools\Db\IQueryRow
    {
        $this->__formsCircleLease->assertActive();
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        $this->setId($this->getInt('id', $data));
        $this->setCircleId($this->get('circle_id', $data));
        $this->setToken($this->get('token', $data));
        $this->setParent($this->getInt('parent', $data));
        $this->setOriginalMountPoint($this->get('mountpoint', $data));
        $this->setOriginalMountPointHash($this->get('mountpoint_hash', $data));
        $this->setMountId($this->get('mount_id', $data));
        $this->setRemote($this->get('remote', $data));
        $this->setRemoteShareId($this->getInt('remote_id', $data));
        $this->getManager()->manageImportFromDatabase($this, $data, $prefix);
        return $this;
    }
    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        $this->__formsCircleLease->assertActive();
        $arr = ['id' => $this->getId(), 'circleId' => $this->getCircleId(), 'mountId' => $this->getMountId(), 'parent' => $this->getParent(), 'owner' => $this->getOwner(), 'token' => $this->getToken(), 'password' => $this->getPassword(), 'originalMountPoint' => $this->getOriginalMountPoint(), 'originalMountPointHash' => $this->getOriginalMountPointHash(), 'alternateMountPoint' => $this->getAlternateMountPoint()];
        if ($this->hasInitiator()) {
            $arr['initiator'] = $this->getInitiator();
            $arr['toMount'] = $this->toMount();
        }
        return $arr;
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
