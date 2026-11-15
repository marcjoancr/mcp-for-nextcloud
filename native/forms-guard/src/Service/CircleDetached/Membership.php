<?php
/* UNAPPLIED R2 proposal. Native source: Model/Membership.php SHA256 a2c597f4ed04ba458b77663f8656178c7846d7bc1a3b8b1ece48662f742b8b3e
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class Membership
 *
 * @package OCA\Circles\Model
 */
final class Membership extends \OCA\Circles\Model\Membership
{
    use \OCA\Circles\Tools\Traits\TArrayTools;
    /** @var string */
    private $singleId = '';
    /** @var string */
    private $circleId = '';
    /** @var int */
    private $circleConfig = 0;
    /** @var int */
    private $level = 0;
    /** @var string */
    private $inheritanceFirst = '';
    /** @var string */
    private $inheritanceLast = '';
    /** @var array */
    private $inheritancePath = [];
    /** @var int */
    private $inheritanceDepth = 0;
    /** @var array */
    private $inheritanceDetails = [];
    /**
     * Membership constructor.
     *
     * @param string $singleId
     * @param Member|null $member
     * @param string $inheritanceLast
     */
    public function __construct(string $singleId = '', string $inheritanceLast = '', ?\OCA\Circles\Model\Member $member = null)
    {
        if (is_null($member)) {
            return;
        }
        $circle = $member->getCircle();
        $this->setSingleId($singleId);
        $this->setCircleId($circle->getSingleId());
        $this->setInheritanceFirst($member->getSingleId());
        $this->setInheritanceLast($inheritanceLast ?: $member->getCircleId());
        $this->setLevel($member->getLevel());
    }
    /**
     * @param string $singleId
     *
     * @return self
     */
    public function setSingleId(string $singleId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->singleId = $singleId;
        return $this;
    }
    /**
     * @return string
     */
    public function getSingleId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->singleId;
    }
    /**
     * @param string $circleId
     *
     * @return Membership
     */
    public function setCircleId(string $circleId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->circleId = $circleId;
        return $this;
    }
    /**
     * @return string
     */
    public function getCircleId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->circleId;
    }
    /**
     * @param int $circleConfig
     *
     * @return Membership
     */
    public function setCircleConfig(int $circleConfig): self
    {
        $this->__formsCircleLease->assertActive();
        $this->circleConfig = $circleConfig;
        return $this;
    }
    /**
     * @return int
     */
    public function getCircleConfig(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->circleConfig;
    }
    /**
     * @param int $level
     *
     * @return Membership
     */
    public function setLevel(int $level): self
    {
        $this->__formsCircleLease->assertActive();
        $this->level = $level;
        return $this;
    }
    /**
     * @return int
     */
    public function getLevel(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->level;
    }
    /**
     * @param string $inheritanceFirst
     *
     * @return Membership
     */
    public function setInheritanceFirst(string $inheritanceFirst): self
    {
        $this->__formsCircleLease->assertActive();
        $this->inheritanceFirst = $inheritanceFirst;
        return $this;
    }
    /**
     * @return string
     */
    public function getInheritanceFirst(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->inheritanceFirst;
    }
    /**
     * @param string $inheritanceLast
     *
     * @return Membership
     */
    public function setInheritanceLast(string $inheritanceLast): self
    {
        $this->__formsCircleLease->assertActive();
        $this->inheritanceLast = $inheritanceLast;
        return $this;
    }
    /**
     * @return string
     */
    public function getInheritanceLast(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->inheritanceLast;
    }
    /**
     * @param array $inheritancePath
     *
     * @return Membership
     */
    public function setInheritancePath(array $inheritancePath): self
    {
        $this->__formsCircleLease->assertActive();
        $this->inheritancePath = $inheritancePath;
        return $this;
    }
    /**
     * @return array
     */
    public function getInheritancePath(): array
    {
        $this->__formsCircleLease->assertActive();
        return $this->inheritancePath;
    }
    /**
     * @param int $inheritanceDepth
     *
     * @return Membership
     */
    public function setInheritanceDepth(int $inheritanceDepth): self
    {
        $this->__formsCircleLease->assertActive();
        $this->inheritanceDepth = $inheritanceDepth;
        return $this;
    }
    /**
     * @return int
     */
    public function getInheritanceDepth(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->inheritanceDepth;
    }
    /**
     * @param array $inheritanceDetails
     *
     * @return Membership
     */
    public function setInheritanceDetails(array $inheritanceDetails): self
    {
        $this->__formsCircleLease->assertActive();
        $this->inheritanceDetails = $inheritanceDetails;
        return $this;
    }
    /**
     * @return array
     */
    public function getInheritanceDetails(): array
    {
        $this->__formsCircleLease->assertActive();
        return $this->inheritanceDetails;
    }
    /**
     * @param array $data
     *
     * @return IDeserializable
     * @throws InvalidItemException
     */
    public function import(array $data): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        if ($this->get('singleId', $data) === '') {
            throw new \OCA\Circles\Tools\Exceptions\InvalidItemException();
        }
        $this->setSingleId($this->get('singleId', $data));
        $this->setCircleId($this->get('circleId', $data));
        $this->setCircleConfig($this->getInt('circleConfig', $data));
        $this->setLevel($this->getInt('level', $data));
        $this->setInheritanceFirst($this->get('inheritanceFirst', $data));
        $this->setInheritanceLast($this->get('inheritanceLast', $data));
        $this->setInheritancePath($this->getArray('inheritancePath', $data));
        $this->setInheritanceDepth($this->getInt('inheritanceDepth', $data));
        return $this;
    }
    /**
     * @param array $data
     * @param string $prefix
     *
     * @return IQueryRow
     * @throws MembershipNotFoundException
     */
    public function importFromDatabase(array $data, string $prefix = ''): \OCA\Circles\Tools\Db\IQueryRow
    {
        $this->__formsCircleLease->assertActive();
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        if ($this->get($prefix . 'single_id', $data) === '') {
            throw new \OCA\Circles\Exceptions\MembershipNotFoundException();
        }
        $this->setSingleId($this->get($prefix . 'single_id', $data));
        $this->setCircleId($this->get($prefix . 'circle_id', $data));
        $this->setLevel($this->getInt($prefix . 'level', $data));
        $this->setCircleConfig($this->getInt($prefix . 'circle_config', $data));
        $this->setInheritanceFirst($this->get($prefix . 'inheritance_first', $data));
        $this->setInheritanceLast($this->get($prefix . 'inheritance_last', $data));
        $this->setInheritancePath($this->getArray($prefix . 'inheritance_path', $data));
        $this->setInheritanceDepth($this->getInt($prefix . 'inheritance_depth', $data));
        return $this;
    }
    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        $this->__formsCircleLease->assertActive();
        $result = ['singleId' => $this->getSingleId(), 'circleId' => $this->getCircleId(), 'circleConfig' => $this->getCircleConfig(), 'level' => $this->getLevel(), 'inheritanceFirst' => $this->getInheritanceFirst(), 'inheritanceLast' => $this->getInheritanceLast(), 'inheritancePath' => $this->getInheritancePath(), 'inheritanceDepth' => $this->getInheritanceDepth()];
        if (!empty($this->getInheritanceDetails())) {
            $result['inheritanceDetails'] = $this->getInheritanceDetails();
        }
        return $result;
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
