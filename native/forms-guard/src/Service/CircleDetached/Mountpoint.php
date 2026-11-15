<?php
/* UNAPPLIED R2 proposal. Native source: Model/Mountpoint.php SHA256 1e47489ea0ee21182870146019ee8cd08fcb09c562fb47216f058383fd720329
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class Mountpoint
 *
 * @package OCA\Circles\Model
 */
final class Mountpoint extends \OCA\Circles\Model\Mountpoint
{
    use \OCA\Circles\Tools\Traits\TArrayTools;
    public function __construct(private string $mountId = '', private string $singleId = '', private string $mountPoint = '')
    {
    }
    public function getMountId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->mountId;
    }
    public function setMountId(string $mountId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->mountId = $mountId;
        return $this;
    }
    public function getSingleId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->singleId;
    }
    public function setSingleId(string $singleId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->singleId = $singleId;
        return $this;
    }
    public function getMountPoint(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->mountPoint;
    }
    public function setMountPoint(string $mountPoint): self
    {
        $this->__formsCircleLease->assertActive();
        $this->mountPoint = $mountPoint;
        return $this;
    }
    public function importFromDatabase(array $data, string $prefix = ''): \OCA\Circles\Tools\Db\IQueryRow
    {
        $this->__formsCircleLease->assertActive();
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        if ($this->get($prefix . 'mountpoint', $data) === '') {
            throw new \OCA\Circles\Exceptions\MountPointNotFoundException();
        }
        $this->setMountId($this->get($prefix . 'mount_id', $data));
        $this->setSingleId($this->get($prefix . 'single_id', $data));
        $this->setMountPoint($this->get($prefix . 'mountpoint', $data));
        return $this;
    }
    public function jsonSerialize(): array
    {
        $this->__formsCircleLease->assertActive();
        return ['mountId' => $this->getMountId(), 'singleId' => $this->getSingleId(), 'mountPoint' => $this->getMountPoint()];
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
