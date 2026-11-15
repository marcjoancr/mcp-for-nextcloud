<?php
/* UNAPPLIED R2 proposal. Native source: Model/CircleInvitation.php SHA256 f97f49ff8bffc45b4afafac97b5d793a9acadc996499256645819480b6f7eb2e
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

final class CircleInvitation extends \OCA\Circles\Model\CircleInvitation
{
    use \OCA\Circles\Tools\Traits\TArrayTools;
    /**
     * @param JsonSerializable $model
     *
     * @return array
     */
    public function serialize(\JsonSerializable $model): array
    {
        $this->__formsCircleLease->assertActive();
        return json_decode(json_encode($model), true);
    }
    /**
     * @param array $data
     *
     * @return array
     */
    public function serializeArray(array $data): array
    {
        $this->__formsCircleLease->assertActive();
        return json_decode(json_encode($data), true);
    }
    /**
     * @param array $data
     * @param string $class
     *
     * @return IDeserializable
     * @throws InvalidItemException
     */
    public function deserialize(array $data, string $class): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        /** @var IDeserializable $item */
        $item = $this->__formsCircleLease->model($class);
        $item->import($data);
        return $item;
    }
    /**
     * @param array $data
     * @param string $class
     * @param bool $associative
     *
     * @return IDeserializable[]
     */
    public function deserializeArray(array $data, string $class, bool $associative = false): array
    {
        $this->__formsCircleLease->assertActive();
        $arr = [];
        foreach ($data as $key => $entry) {
            if (!is_array($entry)) {throw new \RuntimeException('Malformed Circle list entry');}
            try {
                if ($associative) {
                    $arr[$key] = $this->deserialize($entry, $class);
                } else {
                    $arr[] = $this->deserialize($entry, $class);
                }
            } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException $error) {throw new \RuntimeException('Malformed Circle list entry',0,$error);}
        }
        return $arr;
    }
    /**
     * @param string $json
     * @param string $class
     *
     * @return IDeserializable[]
     * @throws InvalidItemException
     */
    public function deserializeList(string $json, string $class): array
    {
        $this->__formsCircleLease->assertActive();
        $arr = [];
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $arr;
        }
        foreach ($data as $entry) {
            $arr[] = $this->deserialize($entry, $class);
        }
        return $arr;
    }
    /**
     * @param string $json
     * @param string $class
     *
     * @return IDeserializable
     * @throws InvalidItemException
     */
    public function deserializeJson(string $json, string $class): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        $data = json_decode($json, true);
        return $this->deserialize($data, $class);
    }
    private string $circleId = '';
    private string $invitationCode = '';
    private string $createdBy = '';
    private int $created = 0;
    public function setCircleId(string $circleId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->circleId = $circleId;
        return $this;
    }
    public function getCircleId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->circleId;
    }
    public function setInvitationCode(string $invitationCode): self
    {
        $this->__formsCircleLease->assertActive();
        $this->invitationCode = $invitationCode;
        return $this;
    }
    public function getInvitationCode(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->invitationCode;
    }
    public function setCreatedBy(string $createdBy): self
    {
        $this->__formsCircleLease->assertActive();
        $this->createdBy = $createdBy;
        return $this;
    }
    public function getCreatedBy(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->createdBy;
    }
    public function setCreated(int $created): self
    {
        $this->__formsCircleLease->assertActive();
        $this->created = $created;
        return $this;
    }
    public function getCreated(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->created;
    }
    public function import(array $data): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        if (!$this->get('circleId', $data)) {
            throw new \OCA\Circles\Tools\Exceptions\InvalidItemException();
        }
        $this->setCircleId($this->get('circleId', $data))->setInvitationCode($this->get('invitationCode', $data))->setCreatedBy($this->get('createdBy', $data))->setCreated($this->getInt('created', $data));
        return $this;
    }
    public function jsonSerialize(): array
    {
        $this->__formsCircleLease->assertActive();
        $arr = ['circleId' => $this->getCircleId(), 'invitationCode' => $this->getInvitationCode(), 'createdBy' => $this->getCreatedBy(), 'created' => $this->getCreated()];
        return $arr;
    }
    public function importFromDatabase(array $data, string $prefix = ''): \OCA\Circles\Tools\Db\IQueryRow
    {
        $this->__formsCircleLease->assertActive();
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        if (!$this->get($prefix . 'circle_id', $data)) {
            throw new \OCA\Circles\Exceptions\CircleInvitationNotFoundException();
        }
        $this->setCircleId($this->get($prefix . 'circle_id', $data))->setInvitationCode($this->get($prefix . 'invitation_code', $data))->setCreatedBy($this->get($prefix . 'created_by', $data));
        $created = $this->get($prefix . 'created', $data);
        $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $created);
        $timestamp = $dateTime ? $dateTime->getTimestamp() : (int) strtotime($created);
        $this->setCreated($timestamp);
        return $this;
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
