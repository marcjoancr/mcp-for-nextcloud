<?php
/* UNAPPLIED R2 proposal. Native source: Model/ShareToken.php SHA256 ea9ce8e95cbea575f52b97153cdd2a0c7f999419d38835bea3ec249d32391651
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

final class ShareToken extends \OCA\Circles\Model\ShareToken
{
    use \OCA\Circles\Tools\Traits\TArrayTools;
    /** @var int */
    private $dbId = 0;
    /** @var int */
    private $shareId = 0;
    /** @var string */
    private $circleId = '';
    /** @var string */
    private $singleId = '';
    /** @var string */
    private $memberId = '';
    /** @var string */
    private $token = '';
    /** @var string */
    private $password = '';
    /** @var int */
    private $accepted = \OCP\Share\IShare::STATUS_PENDING;
    /** @var string */
    private $link = '';
    /**
     * ShareToken constructor.
     */
    public function __construct()
    {
    }
    /**
     * @param int $dbId
     *
     * @return ShareToken
     */
    public function setDbId(int $dbId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->dbId = $dbId;
        return $this;
    }
    /**
     * @return int
     */
    public function getDbId(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->dbId;
    }
    /**
     * @param int $shareId
     *
     * @return ShareToken
     */
    public function setShareId(int $shareId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->shareId = $shareId;
        return $this;
    }
    /**
     * @return int
     */
    public function getShareId(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->shareId;
    }
    /**
     * @param string $circleId
     *
     * @return ShareToken
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
     * @param string $singleId
     *
     * @return ShareToken
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
     * @param string $memberId
     *
     * @return ShareToken
     */
    public function setMemberId(string $memberId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->memberId = $memberId;
        return $this;
    }
    /**
     * @return string
     */
    public function getMemberId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->memberId;
    }
    /**
     * @param string $token
     *
     * @return ShareToken
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
    public function getToken(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->token;
    }
    /**
     * @param string $password
     *
     * @return ShareToken
     */
    public function setPassword(string $password): self
    {
        $this->__formsCircleLease->assertActive();
        $this->password = $password;
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
     * @param int $accepted
     *
     * @return ShareToken
     */
    public function setAccepted(int $accepted): self
    {
        $this->__formsCircleLease->assertActive();
        $this->accepted = $accepted;
        return $this;
    }
    /**
     * @return int
     */
    public function getAccepted(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->accepted;
    }
    /**
     * @param string $link
     *
     * @return ShareToken
     */
    public function setLink(string $link): self
    {
        $this->__formsCircleLease->assertActive();
        $this->link = $link;
        return $this;
    }
    /**
     * @return string
     */
    public function getLink(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->link;
    }
    /**
     * @param array $data
     *
     * @return ShareToken
     * @throws InvalidItemException
     */
    public function import(array $data): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getInt('shareId', $data) === 0) {
            throw new \OCA\Circles\Tools\Exceptions\InvalidItemException();
        }
        $this->setShareId($this->getInt('shareId', $data));
        $this->setCircleId($this->get('circleId', $data));
        $this->setSingleId($this->get('singleId', $data));
        $this->setMemberId($this->get('memberId', $data));
        $this->setToken($this->get('token', $data));
        $this->setPassword($this->get('password', $data));
        $this->setAccepted($this->getInt('accepted', $data, \OCP\Share\IShare::STATUS_PENDING));
        $this->setLink($this->get('link', $data));
        return $this;
    }
    /**
     * @param array $data
     * @param string $prefix
     *
     * @return IQueryRow
     * @throws ShareTokenNotFoundException
     */
    public function importFromDatabase(array $data, string $prefix = ''): \OCA\Circles\Tools\Db\IQueryRow
    {
        $this->__formsCircleLease->assertActive();
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        if ($this->get($prefix . 'token', $data) === '') {
            throw new \OCA\Circles\Exceptions\ShareTokenNotFoundException();
        }
        $this->setShareId($this->getInt($prefix . 'share_id', $data));
        $this->setCircleId($this->get($prefix . 'circle_id', $data));
        $this->setSingleId($this->get($prefix . 'single_id', $data));
        $this->setMemberId($this->get($prefix . 'member_id', $data));
        $this->setToken($this->get($prefix . 'token', $data));
        $this->setPassword($this->get($prefix . 'password', $data));
        $this->setAccepted($this->getInt($prefix . 'accepted', $data, \OCP\Share\IShare::STATUS_PENDING));
        return $this;
    }
    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        $this->__formsCircleLease->assertActive();
        return ['shareId' => $this->getShareId(), 'circleId' => $this->getCircleId(), 'singleId' => $this->getSingleId(), 'memberId' => $this->getMemberId(), 'token' => $this->getToken(), 'password' => $this->getPassword(), 'accepted' => $this->getAccepted(), 'link' => $this->getLink()];
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
