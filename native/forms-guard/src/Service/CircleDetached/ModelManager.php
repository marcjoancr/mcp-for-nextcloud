<?php
/* UNAPPLIED R2 proposal. Native source: Model/ModelManager.php SHA256 8fba5629a734314d85dca6bec86d722c766861fb4a11cb5cca647366c054709f
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class ModelManager
 *
 * @package OCA\Circles\Model
 */
final class ModelManager extends \OCA\Circles\Model\ModelManager
{
    use \OCA\Circles\Tools\Traits\TNCLogger;
    private bool $fullDetails = false;
    public function __construct()
    {
    }
    /**
     * @return ConfigService
     */
    public function getConfigService(): \OCA\Circles\Service\ConfigService
    {
        $this->__formsCircleLease->assertActive();
        return $this->__formsCircleLease->config();
    }
    /**
     * @param Circle $circle
     */
    public function getMembers(\OCA\Circles\Model\Circle $circle, int $limit = 0): void
    {
        $this->__formsCircleLease->assertActive();
        try {
            $circle->setMembers($this->__formsCircleLease->members()->getMembers($circle->getSingleId(), limit: $limit));
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
            // TODO: debug log
        }
    }
    /**
     * @param Circle $circle
     * @param bool $detailed
     */
    public function getInheritedMembers(\OCA\Circles\Model\Circle $circle, bool $detailed = false): void
    {
        $this->__formsCircleLease->assertActive();
        try {
            $circle->setInheritedMembers($this->__formsCircleLease->members()->getInheritedMembers($circle->getSingleId(), $detailed), $detailed);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
            // TODO: debug log
        }
    }
    /**
     * @param Circle $circle
     * @param bool $detailed
     *
     * @throws RemoteNotFoundException
     * @throws RequestBuilderException
     * @throws FederatedItemException
     * @throws RemoteInstanceException
     * @throws RemoteResourceNotFoundException
     * @throws UnknownRemoteException
     */
    public function getRemoteInheritedMembers(\OCA\Circles\Model\Circle $circle, bool $detailed = false): void
    {
        throw new \RuntimeException("Remote Circle reads are not qualified");
    }
    /**
     * @param IEntity $member
     */
    public function getMemberships(\OCA\Circles\IEntity $member): void
    {
        $this->__formsCircleLease->assertActive();
        $memberships = $this->__formsCircleLease->memberships()->getMemberships($member->getSingleId());
        $member->setMemberships($memberships);
    }
    public function getLink(\OCA\Circles\IEntity $member, string $circleId, bool $detailed = false): \OCA\Circles\Model\Membership
    {
        return $this->__formsCircleLease->membership($circleId, $member->getSingleId(), $detailed);
    }
    /**
     * @param ManagedModel $model
     * @param array $data
     * @param string $base
     */
    public function manageImportFromDatabase(\OCA\Circles\Model\ManagedModel $model, array $data, string $base): void
    {
        $this->__formsCircleLease->assertActive();
        if ($model instanceof \OCA\Circles\Model\Circle) {
            if ($base === '') {
                $base = \OCA\Circles\Db\CoreQueryBuilder::CIRCLE;
            }
        }
        if ($model instanceof \OCA\Circles\Model\CircleInvitation) {
            if ($base === '') {
                $base = \OCA\Circles\Db\CoreQueryBuilder::INVITATION;
            }
        }
        if ($model instanceof \OCA\Circles\Model\Member) {
            if ($base === '') {
                $base = \OCA\Circles\Db\CoreQueryBuilder::MEMBER;
            }
        }
        if ($model instanceof \OCA\Circles\Model\ShareWrapper) {
            if ($base === '') {
                $base = \OCA\Circles\Db\CoreQueryBuilder::SHARE;
            }
        }
        if ($model instanceof \OCA\Circles\Model\Mount) {
            if ($base === '') {
                $base = \OCA\Circles\Db\CoreQueryBuilder::MOUNT;
            }
        }
        foreach ($this->__formsCircleLease->query()->getAvailablePath($base) as $path => $prefix) {
            $this->importBasedOnPath($model, $data, $path, $prefix);
        }
    }
    private function importBasedOnPath(\OCA\Circles\Model\ManagedModel $model, array $data, string $path, string $prefix)
    {
        $this->__formsCircleLease->assertActive();
        if ($model instanceof \OCA\Circles\Model\Circle) {
            $this->importIntoCircle($model, $data, $path, $prefix);
        }
        if ($model instanceof \OCA\Circles\Model\Member) {
            $this->importIntoMember($model, $data, $path, $prefix);
        }
        if ($model instanceof \OCA\Circles\Model\FederatedUser) {
            $this->importIntoFederatedUser($model, $data, $path, $prefix);
        }
        if ($model instanceof \OCA\Circles\Model\ShareWrapper) {
            $this->importIntoShareWrapper($model, $data, $path, $prefix);
        }
        if ($model instanceof \OCA\Circles\Model\Mount) {
            $this->importIntoMount($model, $data, $path, $prefix);
        }
    }
    /**
     * @param Circle $circle
     * @param array $data
     * @param string $path
     * @param string $prefix
     */
    private function importIntoCircle(\OCA\Circles\Model\Circle $circle, array $data, string $path, string $prefix): void
    {
        $this->__formsCircleLease->assertActive();
        switch ($path) {
            case \OCA\Circles\Db\CoreQueryBuilder::OWNER:
                try {
                    $owner = $this->__formsCircleLease->model(\OCA\Circles\Model\Member::class, []);
                    $owner->importFromDatabase($data, $prefix);
                    $circle->setOwner($owner);
                } catch (\OCA\Circles\Exceptions\MemberNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::INITIATOR:
                try {
                    $initiator = $this->__formsCircleLease->model(\OCA\Circles\Model\Member::class, []);
                    $initiator->importFromDatabase($data, $prefix);
                    $circle->setInitiator($initiator);
                } catch (\OCA\Circles\Exceptions\MemberNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::DIRECT_INITIATOR:
                try {
                    $directInitiator = $this->__formsCircleLease->model(\OCA\Circles\Model\Member::class, []);
                    $directInitiator->importFromDatabase($data, $prefix);
                    $circle->setDirectInitiator($directInitiator);
                } catch (\OCA\Circles\Exceptions\MemberNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::INVITATION:
                try {
                    $circleInvitation = $this->__formsCircleLease->model(\OCA\Circles\Model\CircleInvitation::class, []);
                    $circleInvitation->importFromDatabase($data, $prefix);
                    $circle->setCircleInvitation($circleInvitation);
                } catch (\OCA\Circles\Exceptions\CircleInvitationNotFoundException $e) {
                }
                break;
        }
    }
    /**
     * @param Member $member
     * @param array $data
     * @param string $path
     * @param string $prefix
     */
    private function importIntoMember(\OCA\Circles\Model\Member $member, array $data, string $path, string $prefix): void
    {
        $this->__formsCircleLease->assertActive();
        switch ($path) {
            case \OCA\Circles\Db\CoreQueryBuilder::CIRCLE:
                try {
                    $circle = $this->__formsCircleLease->model(\OCA\Circles\Model\Circle::class, []);
                    $circle->importFromDatabase($data, $prefix);
                    $member->setCircle($circle);
                } catch (\OCA\Circles\Exceptions\CircleNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::BASED_ON:
                try {
                    $circle = $this->__formsCircleLease->model(\OCA\Circles\Model\Circle::class, []);
                    $circle->importFromDatabase($data, $prefix);
                    $member->setBasedOn($circle);
                } catch (\OCA\Circles\Exceptions\CircleNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::INHERITED_BY:
                try {
                    $inheritedBy = $this->__formsCircleLease->model(\OCA\Circles\Model\FederatedUser::class, []);
                    $inheritedBy->importFromDatabase($data, $prefix);
                    $member->setInheritedBy($inheritedBy);
                } catch (\OCA\Circles\Exceptions\FederatedUserNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::INVITED_BY:
                try {
                    $invitedByCircle = $this->__formsCircleLease->model(\OCA\Circles\Model\Circle::class, []);
                    $invitedByCircle->importFromDatabase($data, $prefix);
                    $invitedBy = $this->__formsCircleLease->model(\OCA\Circles\Model\FederatedUser::class, []);
                    $invitedBy->importFromCircle($invitedByCircle);
                    $member->setInvitedBy($invitedBy);
                } catch (\OCA\Circles\Exceptions\CircleNotFoundException|\OCA\Circles\Exceptions\OwnerNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::INHERITANCE_FROM:
                try {
                    $inheritanceFrom = $this->__formsCircleLease->model(\OCA\Circles\Model\Member::class, []);
                    $inheritanceFrom->importFromDatabase($data, $prefix);
                    $member->setInheritanceFrom($inheritanceFrom);
                } catch (\OCA\Circles\Exceptions\MemberNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::REMOTE:
                RemoteJoin::assertAbsent($data, $prefix);
                break;
        }
    }
    /**
     * @param FederatedUser $federatedUser
     * @param array $data
     * @param string $path
     * @param string $prefix
     */
    private function importIntoFederatedUser(\OCA\Circles\Model\FederatedUser $federatedUser, array $data, string $path, string $prefix): void
    {
        $this->__formsCircleLease->assertActive();
        switch ($path) {
            case \OCA\Circles\Db\CoreQueryBuilder::MEMBERSHIPS:
                try {
                    $membership = $this->__formsCircleLease->model(\OCA\Circles\Model\Membership::class, []);
                    $membership->importFromDatabase($data, $prefix);
                    $federatedUser->setInheritance($membership);
                } catch (\OCA\Circles\Exceptions\MembershipNotFoundException) {
                }
                break;
        }
    }
    /**
     * @param ShareWrapper $shareWrapper
     * @param array $data
     * @param string $path
     * @param string $prefix
     */
    private function importIntoShareWrapper(\OCA\Circles\Model\ShareWrapper $shareWrapper, array $data, string $path, string $prefix): void
    {
        $this->__formsCircleLease->assertActive();
        switch ($path) {
            case \OCA\Circles\Db\CoreQueryBuilder::CIRCLE:
                try {
                    $circle = $this->__formsCircleLease->model(\OCA\Circles\Model\Circle::class, []);
                    $circle->importFromDatabase($data, $prefix);
                    $shareWrapper->setCircle($circle);
                } catch (\OCA\Circles\Exceptions\CircleNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::INITIATOR:
                try {
                    $initiator = $this->__formsCircleLease->model(\OCA\Circles\Model\Member::class, []);
                    $initiator->importFromDatabase($data, $prefix);
                    $shareWrapper->setInitiator($initiator);
                } catch (\OCA\Circles\Exceptions\MemberNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::INHERITED_BY:
                try {
                    $inheritedBy = $this->__formsCircleLease->model(\OCA\Circles\Model\Member::class, []);
                    $inheritedBy->importFromDatabase($data, $prefix);
                    $shareWrapper->setInitiator($inheritedBy);
                } catch (\OCA\Circles\Exceptions\MemberNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::FILE_CACHE:
                try {
                    $fileCache = $this->__formsCircleLease->model(\OCA\Circles\Model\FileCacheWrapper::class, []);
                    $fileCache->importFromDatabase($data, $prefix);
                    $shareWrapper->setFileCache($fileCache);
                } catch (\OCA\Circles\Exceptions\FileCacheNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::TOKEN:
                try {
                    $token = $this->__formsCircleLease->model(\OCA\Circles\Model\ShareToken::class, []);
                    $token->importFromDatabase($data, $prefix);
                    $shareWrapper->setShareToken($token);
                } catch (\OCA\Circles\Exceptions\ShareTokenNotFoundException) {
                }
                break;
        }
    }
    /**
     * @param Mount $mount
     * @param array $data
     * @param string $path
     * @param string $prefix
     */
    private function importIntoMount(\OCA\Circles\Model\Mount $mount, array $data, string $path, string $prefix): void
    {
        $this->__formsCircleLease->assertActive();
        switch ($path) {
            case \OCA\Circles\Db\CoreQueryBuilder::MEMBER:
                try {
                    $member = $this->__formsCircleLease->model(\OCA\Circles\Model\Member::class, []);
                    $member->importFromDatabase($data, $prefix);
                    $mount->setOwner($member);
                } catch (\OCA\Circles\Exceptions\MemberNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::INITIATOR:
                try {
                    $initiator = $this->__formsCircleLease->model(\OCA\Circles\Model\Member::class, []);
                    $initiator->importFromDatabase($data, $prefix);
                    $mount->setInitiator($initiator);
                } catch (\OCA\Circles\Exceptions\MemberNotFoundException) {
                }
                break;
            case \OCA\Circles\Db\CoreQueryBuilder::MOUNTPOINT:
                try {
                    $mountPoint = $this->__formsCircleLease->model(\OCA\Circles\Model\Mountpoint::class, []);
                    $mountPoint->importFromDatabase($data, $prefix);
                    $mount->setAlternateMountPoint($mountPoint);
                } catch (\OCA\Circles\Exceptions\MountPointNotFoundException) {
                }
                break;
        }
    }
    public function getLocalInstance(): string
    {
        return $this->__formsCircleLease->config()->getLoopbackInstance();
    }
    /**
     * @param string $instance
     *
     * @return bool
     */
    public function isLocalInstance(string $instance): bool
    {
        $this->__formsCircleLease->assertActive();
        return $this->__formsCircleLease->config()->isLocalInstance($instance);
    }
    public function fixInstance(string $instance): string
    {
        $this->__formsCircleLease->assertActive();
        return $instance;
    }
    public function generateLinkToCircle(string $singleId): string
    {
        return $this->__formsCircleLease->url()->linkToRouteAbsolute("circles.Page.index") . "/team/" . $singleId;
    }
    /**
     * @param bool $full
     */
    public function setFullDetails(bool $full): void
    {
        $this->__formsCircleLease->assertActive();
        $this->fullDetails = $full;
    }
    /**
     * @return bool
     */
    public function isFullDetails(): bool
    {
        $this->__formsCircleLease->assertActive();
        return $this->fullDetails;
    }
    private Lease $__formsCircleLease;
    final public function bindCircleLease(Lease $lease): void
    {
        if (isset($this->__formsCircleLease)) {
            throw new \LogicException("Circle model is already bound");
        }
        $this->__formsCircleLease = $lease;
    }
/**
 * @param Throwable $t
 * @param array $serializable
 */
public function t(\Throwable $t, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param Throwable $t
 * @param int $level
 * @param array $serializable
 */
public function throwable(\Throwable $t, int $level = 3, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param Exception $e
 * @param array $serializable
 */
public function e(\Exception $e, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param Exception $e
 * @param int|array $level
 * @param array $serializable
 */
public function exception(\Exception $e, $level = 3, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function emergency(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function alert(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function warning(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function notice(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param array $serializable
 */
public function debug(string $message, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param int $level
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function log(int $level, string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @return LoggerInterface
 */
public function logger(): \Psr\Log\LoggerInterface
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 * @param string $value
 *
 * @param string $default
 *
 * @return string
 */
public function setup(string $key, string $value = '', string $default = ''): string
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 * @param array $value
 * @param array $default
 *
 * @return array
 */
public function setupArray(string $key, array $value = [], array $default = []): array
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 * @param int $value
 * @param int $default
 *
 * @return int
 */
public function setupInt(string $key, int $value = -999, int $default = 0): int
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 *
 * @return string
 */
public function appConfig(string $key): string
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
}
