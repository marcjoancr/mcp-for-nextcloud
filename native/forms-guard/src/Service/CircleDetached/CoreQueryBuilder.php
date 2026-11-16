<?php
/* UNAPPLIED R2 proposal. Native source: Db/CoreQueryBuilder.php SHA256 c26fa10efc8022e04be50f8eefa193a255ecc8189e2044c2c4fa2f0a10e99849
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class CoreQueryBuilder
 *
 * @package OCA\Circles\Db
 */
final class CoreQueryBuilder extends \OCA\Circles\Db\CoreQueryBuilder
{
    use \OCA\Circles\Tools\Traits\TArrayTools;
    public const SINGLE = 'a';
    public const CIRCLE = 'b';
    public const MEMBER = 'c';
    public const OWNER = 'd';
    public const FEDERATED_EVENT = 'e';
    public const REMOTE = 'f';
    public const BASED_ON = 'g';
    public const INITIATOR = 'h';
    public const DIRECT_INITIATOR = 'i';
    public const MEMBERSHIPS = 'j';
    public const CONFIG = 'k';
    public const UPSTREAM_MEMBERSHIPS = 'l';
    public const INHERITANCE_FROM = 'm';
    public const INHERITED_BY = 'n';
    public const INVITED_BY = 'o';
    public const MOUNT = 'p';
    public const MOUNTPOINT = 'q';
    public const SHARE = 'r';
    public const FILE_CACHE = 's';
    public const STORAGES = 't';
    public const TOKEN = 'u';
    public const OPTIONS = 'v';
    public const HELPER = 'w';
    public const INVITATION = 'x';
    public static $SQL_PATH = [self::SINGLE => [self::MEMBER], self::CIRCLE => [self::OPTIONS => [], self::MEMBER, self::INVITATION, self::OWNER => [self::BASED_ON], self::MEMBERSHIPS => [self::CONFIG], self::DIRECT_INITIATOR => [self::BASED_ON], self::INITIATOR => [self::BASED_ON, self::INHERITED_BY => [self::MEMBERSHIPS]], self::REMOTE => [self::MEMBER, self::CIRCLE => [self::OWNER]]], self::MEMBER => [self::MEMBERSHIPS => [self::CONFIG], self::INHERITANCE_FROM, self::CIRCLE => [self::OPTIONS => ['getData' => true], self::OWNER, self::MEMBERSHIPS => [self::CONFIG], self::DIRECT_INITIATOR, self::INITIATOR => [self::OPTIONS => ['minimumLevel' => \OCA\Circles\Model\Member::LEVEL_MEMBER], self::BASED_ON, self::INHERITED_BY => [self::MEMBERSHIPS], self::INVITED_BY => [self::OWNER, self::BASED_ON]]], self::BASED_ON => [self::OWNER, self::MEMBERSHIPS, self::INITIATOR => [self::BASED_ON, self::INHERITED_BY => [self::MEMBERSHIPS]]], self::REMOTE => [self::MEMBER, self::CIRCLE => [self::OWNER]], self::INVITED_BY => [self::OWNER, self::BASED_ON]], self::MEMBERSHIPS => [self::CONFIG], self::SHARE => [self::SHARE, self::TOKEN, self::FILE_CACHE => [self::STORAGES], self::UPSTREAM_MEMBERSHIPS => [self::MEMBERSHIPS, self::INHERITED_BY => [self::BASED_ON], self::SHARE], self::MEMBERSHIPS => [self::CONFIG], self::INHERITANCE_FROM, self::INHERITED_BY => [self::BASED_ON], self::CIRCLE => [self::OWNER], self::INITIATOR => [self::BASED_ON, self::INHERITED_BY => [self::MEMBERSHIPS]]], self::REMOTE => [self::MEMBER], self::MOUNT => [self::MEMBER => [self::REMOTE], self::INITIATOR => [self::INHERITED_BY => [self::MEMBERSHIPS]], self::MOUNTPOINT, self::MEMBERSHIPS => [self::CONFIG]], self::HELPER => [self::MEMBERSHIPS => [self::CONFIG], self::INITIATOR => [self::INHERITED_BY => [self::MEMBERSHIPS]], self::CIRCLE => [self::OPTIONS => [], self::MEMBER, self::OWNER => [self::BASED_ON]]]];
    /** @var ConfigService */
    private $configService;
    private array $options = [];
    private array $sqlPath = [];
    public function __construct()
    {
        \OC\DB\QueryBuilder\QueryBuilder::__construct($this->__formsCircleLease->db(), $this->__formsCircleLease->system(), $this->__formsCircleLease->logger());
        $this->configService = $this->__formsCircleLease->config();
    }
    /**
     * @param IFederatedModel $federatedModel
     *
     * @return string
     */
    public function getInstance(\OCA\Circles\IFederatedModel $federatedModel): string
    {
        $this->__formsCircleLease->assertActive();
        $instance = $federatedModel->getInstance();
        return $this->configService->isLocalInstance($instance) ? '' : $instance;
    }
    /**
     * @param string $id
     */
    public function limitToCircleId(string $id): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limit('circle_id', $id, '', true);
    }
    /**
     * @param string $name
     */
    public function limitToName(string $name): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limit('name', $name);
    }
    /**
     * @param string $name
     */
    public function limitToDisplayName(string $name): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limit('display_name', $name, '', false);
    }
    /**
     * @param string $name
     */
    public function limitToSanitizedName(string $name): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limit('sanitized_name', $name, '', false);
    }
    /**
     * @param int $config
     */
    public function limitToConfig(int $config): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limitInt('config', $config);
    }
    /**
     * @param int $source
     */
    public function limitToSource(int $source): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limitInt('source', $source);
    }
    /**
     * @param int $config
     * @param string $alias
     */
    public function limitToConfigFlag(int $config, string $alias = ''): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limitBitwise('config', $config, $alias);
    }
    /**
     * @param string $singleId
     */
    public function limitToSingleId(string $singleId, string $alias = ''): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limit('single_id', $singleId, $alias, true);
    }
    public function limitToUserId(string $userId, string $alias = ''): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limit('user_id', $userId, $alias, true);
    }
    /**
     * @param string $itemId
     */
    public function limitToItemId(string $itemId): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limit('item_id', $itemId, '', true);
    }
    /**
     * @param string $host
     */
    public function limitToInstance(string $host): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limit('instance', $host, '', false);
    }
    /**
     * @param int $userType
     */
    public function limitToUserType(int $userType): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limitInt('user_type', $userType);
    }
    /**
     * @param int $shareType
     */
    public function limitToShareType(int $shareType): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limitInt('share_type', $shareType);
    }
    /**
     * @param string $shareWith
     */
    public function limitToShareWith(string $shareWith): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limit('share_with', $shareWith, '', true);
    }
    /**
     * @param int $nodeId
     */
    public function limitToFileSource(int $nodeId): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limitInt('file_source', $nodeId);
    }
    public function limitToFileTarget(string $target, string $alias): void
    {
        $this->__formsCircleLease->assertActive();
        $this->andWhere($this->expr()->orX($this->exprLimit('file_target', $target, $alias), $this->expr()->andX($this->exprLimitNull('file_target', false, $alias), $this->exprLimit('file_target', $target))));
    }
    public function limitToFileTargetLike(string $target, string $alias): void
    {
        $this->__formsCircleLease->assertActive();
        $this->andWhere($this->expr()->orX($this->exprLike('file_target', $target, $alias), $this->expr()->andX($this->exprLimitNull('file_target', false, $alias), $this->exprLike('file_target', $target))));
    }
    /**
     * @param array $files
     */
    public function limitToFileSourceArray(array $files): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limitInArray('file_source', $files, type: \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY);
    }
    /**
     * @param int $shareId
     */
    public function limitToShareParent(int $shareId): void
    {
        $this->__formsCircleLease->assertActive();
        $this->limitInt('parent', $shareId);
    }
    /**
     * filter result on details (ie. displayName, Description, ...)
     *
     * @param Circle $circle
     */
    public function filterCircleDetails(\OCA\Circles\Model\Circle $circle): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        $expr = $this->expr();
        $andX = null;
        if ($circle->getDisplayName() !== '') {
            $andX = [];
            foreach (explode(' ', $circle->getDisplayName()) as $word) {
                $andX[] = $expr->iLike($this->getDefaultSelectAlias() . '.' . 'display_name', $this->createNamedParameter('%' . $word . '%'));
            }
        }
        if ($andX !== null) {
            $this->andWhere(...$andX);
        }
        if ($circle->getSource() > 0) {
            $this->limitInt('source', $circle->getSource());
        }
        if ($circle->getConfig() > 0) {
            $this->limitBitwise('config', $circle->getConfig());
        }
    }
    /**
     * left join RemoteInstance based on a Member
     */
    public function leftJoinRemoteInstance(string $alias): void
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        try {
            $aliasRemoteInstance = $this->generateAlias($alias, self::REMOTE);
            $this->generateRemoteInstanceSelectAlias($aliasRemoteInstance)->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_REMOTE, $aliasRemoteInstance, $expr->eq($alias . '.instance', $aliasRemoteInstance . '.instance'));
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
        }
    }
    /**
     * @param string $alias
     * @param RemoteInstance $remoteInstance
     * @param bool $filterSensitiveData
     * @param string $aliasCircle
     *
     * @throws RequestBuilderException
     */
    public function limitToRemoteInstance(string $alias, \OCA\Circles\Model\Federated\RemoteInstance $remoteInstance, bool $filterSensitiveData = true, string $aliasCircle = ''): void
    {
        $this->__formsCircleLease->assertActive();
        if ($aliasCircle === '') {
            $aliasCircle = $alias;
        }
        $this->leftJoinRemoteInstanceIncomingRequest($alias, $remoteInstance);
        $this->leftJoinMemberFromInstance($alias, $remoteInstance, $aliasCircle);
        $this->leftJoinMemberFromRemoteCircle($alias, $remoteInstance, $aliasCircle);
        $this->limitRemoteVisibility($alias, $filterSensitiveData, $aliasCircle);
    }
    /**
     * Left join RemoteInstance based on an incoming request
     *
     * @param string $alias
     * @param RemoteInstance $remoteInstance
     *
     * @throws RequestBuilderException
     */
    public function leftJoinRemoteInstanceIncomingRequest(string $alias, \OCA\Circles\Model\Federated\RemoteInstance $remoteInstance): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        $aliasRemote = $this->generateAlias($alias, self::REMOTE);
        $expr = $this->expr();
        $this->leftJoin($this->getDefaultSelectAlias(), \OCA\Circles\Db\CoreRequestBuilder::TABLE_REMOTE, $aliasRemote, $expr->eq($aliasRemote . '.instance', $this->createNamedParameter($remoteInstance->getInstance())));
    }
    /**
     * left join members to check memberships of someone from instance
     *
     * @param string $alias
     * @param RemoteInstance $remoteInstance
     * @param string $aliasCircle
     *
     * @throws RequestBuilderException
     */
    private function leftJoinMemberFromInstance(string $alias, \OCA\Circles\Model\Federated\RemoteInstance $remoteInstance, string $aliasCircle)
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        $aliasRemote = $this->generateAlias($alias, self::REMOTE);
        $aliasRemoteMember = $this->generateAlias($aliasRemote, self::MEMBER);
        $expr = $this->expr();
        $this->leftJoin($this->getDefaultSelectAlias(), \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasRemoteMember, $expr->andX($expr->eq($aliasRemoteMember . '.circle_id', $aliasCircle . '.unique_id'), $expr->eq($aliasRemoteMember . '.instance', $this->createNamedParameter($remoteInstance->getInstance())), $expr->gte($aliasRemoteMember . '.level', $this->createNamedParameter(\OCA\Circles\Model\Member::LEVEL_MEMBER))));
    }
    /**
     * left join circle is member of a circle from remote instance
     *
     * @param string $alias
     * @param RemoteInstance $remoteInstance
     * @param string $aliasCircle
     *
     * @throws RequestBuilderException
     */
    private function leftJoinMemberFromRemoteCircle(string $alias, \OCA\Circles\Model\Federated\RemoteInstance $remoteInstance, string $aliasCircle)
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        $aliasRemote = $this->generateAlias($alias, self::REMOTE);
        $aliasRemoteCircle = $this->generateAlias($aliasRemote, self::CIRCLE);
        $aliasRemoteCircleOwner = $this->generateAlias($aliasRemoteCircle, self::OWNER);
        $expr = $this->expr();
        $this->leftJoin($this->getDefaultSelectAlias(), \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasRemoteCircle, $expr->andX($expr->eq($aliasRemoteCircle . '.single_id', $aliasCircle . '.unique_id'), $expr->emptyString($aliasRemoteCircle . '.instance'), $expr->gte($aliasRemoteCircle . '.level', $this->createNamedParameter(\OCA\Circles\Model\Member::LEVEL_MEMBER))));
        $this->leftJoin($this->getDefaultSelectAlias(), \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasRemoteCircleOwner, $expr->andX($expr->eq($aliasRemoteCircle . '.circle_id', $aliasRemoteCircleOwner . '.circle_id'), $expr->eq($aliasRemoteCircleOwner . '.instance', $this->createNamedParameter($remoteInstance->getInstance())), $expr->eq($aliasRemoteCircleOwner . '.level', $this->createNamedParameter(\OCA\Circles\Model\Member::LEVEL_OWNER))));
    }
    /**
     * - global_scale: visibility on all Circles
     * - trusted: visibility on all FEDERATED Circle if owner is local
     * - external: visibility on all FEDERATED Circle if owner is local and:
     *    - with if Circle contains at least one member from the remote instance
     *    - one circle from the remote instance contains the local circle as member, and confirmed (using
     *      sync locally)
     * - passive: like external, but the members list will only contains member from the local instance and
     * from the remote instance.
     *
     * @param string $alias
     * @param bool $sensitive
     * @param string $aliasCircle
     *
     * @throws RequestBuilderException
     */
    protected function limitRemoteVisibility(string $alias, bool $sensitive, string $aliasCircle)
    {
        $this->__formsCircleLease->assertActive();
        $aliasRemote = $this->generateAlias($alias, self::REMOTE);
        $aliasOwner = $this->generateAlias($aliasCircle, self::OWNER);
        $aliasRemoteMember = $this->generateAlias($aliasRemote, self::MEMBER);
        $aliasRemoteCircle = $this->generateAlias($aliasRemote, self::CIRCLE);
        $aliasRemoteCircleOwner = $this->generateAlias($aliasRemoteCircle, self::OWNER);
        $expr = $this->expr();
        $orX = [$expr->eq($aliasRemote . '.type', $this->createNamedParameter(\OCA\Circles\Model\Federated\RemoteInstance::TYPE_GLOBALSCALE))];
        $orExtOrPassive = [$expr->eq($aliasRemote . '.type', $this->createNamedParameter(\OCA\Circles\Model\Federated\RemoteInstance::TYPE_EXTERNAL))];
        if (!$sensitive) {
            $orExtOrPassive[] = $expr->eq($aliasRemote . '.type', $this->createNamedParameter(\OCA\Circles\Model\Federated\RemoteInstance::TYPE_PASSIVE));
        } else if ($this->getDefaultSelectAlias() === \OCA\Circles\Db\CoreQueryBuilder::MEMBER) {
            $orExtOrPassive[] = $this->limitRemoteVisibility_Sensitive_Members($aliasRemote);
        }
        $orInstance = $expr->orX($expr->isNotNull($aliasRemoteMember . '.instance'), $expr->isNotNull($aliasRemoteCircleOwner . '.instance'));
        $andExternal = $expr->andX($expr->orX(...$orExtOrPassive), $orInstance);
        $orExtOrTrusted = $expr->orX($andExternal, $expr->eq($aliasRemote . '.type', $this->createNamedParameter(\OCA\Circles\Model\Federated\RemoteInstance::TYPE_TRUSTED)));
        $orX[] = $expr->andX($orExtOrTrusted, $this->exprLimitBitwise('config', \OCA\Circles\Model\Circle::CFG_FEDERATED, $aliasCircle), $expr->emptyString($aliasOwner . '.instance'));
        $this->andWhere($expr->orX(...$orX));
    }
    /**
     * @param string $alias
     * @param Member $member
     *
     * @throws RequestBuilderException
     */
    public function limitToDirectMembership(string $alias, \OCA\Circles\Model\Member $member): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        $aliasMember = $this->generateAlias($alias, self::MEMBER, $options);
        $getData = $this->getBool('getData', $options, false);
        $expr = $this->expr();
        if ($getData) {
            $this->generateMemberSelectAlias($aliasMember);
        }
        $this->innerJoin($this->getDefaultSelectAlias(), \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasMember, $expr->eq($aliasMember . '.circle_id', $alias . '.unique_id'));
        $this->filterDirectMembership($aliasMember, $member);
    }
    /**
     * @param string $alias
     * @param string $aliasCircle
     * @param FederatedUser $federatedUser
     *
     * @throws RequestBuilderException
     */
    public function limitToFederatedUserMemberships(string $alias, string $aliasCircle, \OCA\Circles\Model\FederatedUser $federatedUser): void
    {
        $this->__formsCircleLease->assertActive();
        $aliasMembership = $this->generateAlias($alias, self::MEMBERSHIPS);
        $this->leftJoin($aliasCircle, 'circles_membership', $aliasMembership, $this->expr()->eq($aliasCircle . '.unique_id', $aliasMembership . '.circle_id'));
        $this->andWhere($this->expr()->eq($aliasMembership . '.single_id', $this->createNamedParameter($federatedUser->getSingleId())));
    }
    /**
     * @param string $aliasMember
     * @param Member $member
     */
    public function filterDirectMembership(string $aliasMember, \OCA\Circles\Model\Member $member): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        $expr = $this->expr();
        $andX = [];
        if ($member->getUserId() !== '') {
            $andX[] = $expr->eq($aliasMember . '.user_id', $this->createNamedParameter($member->getUserId()));
        }
        if ($member->getSingleId() !== '') {
            $andX[] = $expr->eq($aliasMember . '.single_id', $this->createNamedParameter($member->getSingleId()));
        }
        if ($member->getUserType() > 0) {
            $andX[] = $expr->eq($aliasMember . '.user_type', $this->createNamedParameter($member->getUserType()));
        }
        $this->limitToInstance($this->getInstance($member));
        if ($member->getLevel() > 0) {
            $andX[] = $expr->gte($aliasMember . '.level', $this->createNamedParameter($member->getLevel(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
        }
        $this->andWhere($expr->andX(...$andX));
    }
    /**
     * @param string $alias
     * @param IFederatedUser|null $initiator
     * @param string $field
     * @param string $helperAlias
     *
     * @throws RequestBuilderException
     */
    public function leftJoinCircle(string $alias, ?\OCA\Circles\IFederatedUser $initiator = null, string $field = 'circle_id', string $helperAlias = ''): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        $helperAlias = $helperAlias !== '' ? $helperAlias : $alias;
        $aliasCircle = $this->generateAlias($alias, self::CIRCLE, $options);
        $getData = $this->getBool('getData', $options, false);
        $expr = $this->expr();
        if ($getData) {
            $this->generateCircleSelectAlias($aliasCircle);
        }
        $this->leftJoin($helperAlias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_CIRCLE, $aliasCircle, $expr->eq($aliasCircle . '.unique_id', $helperAlias . '.' . $field));
        if (!is_null($initiator)) {
            $this->limitToInitiator($aliasCircle, $initiator);
        }
        $this->leftJoinOwner($aliasCircle);
    }
    /**
     * @param string $aliasMember
     *
     * @throws RequestBuilderException
     */
    public function leftJoinInvitedBy(string $aliasMember): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        try {
            $aliasInvitedBy = $this->generateAlias($aliasMember, self::INVITED_BY);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
            return;
        }
        $expr = $this->expr();
        $this->generateCircleSelectAlias($aliasInvitedBy)->leftJoin($aliasMember, \OCA\Circles\Db\CoreRequestBuilder::TABLE_CIRCLE, $aliasInvitedBy, $expr->eq($aliasMember . '.invited_by', $aliasInvitedBy . '.unique_id'));
        $this->leftJoinOwner($aliasInvitedBy);
    }
    /**
     * @param string $aliasMember
     * @param IFederatedUser|null $initiator
     *
     * @throws RequestBuilderException
     */
    public function leftJoinBasedOn(string $aliasMember, ?\OCA\Circles\IFederatedUser $initiator = null): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        try {
            $aliasBasedOn = $this->generateAlias($aliasMember, self::BASED_ON, $options);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
            return;
        }
        $expr = $this->expr();
        $this->generateCircleSelectAlias($aliasBasedOn)->leftJoin($aliasMember, \OCA\Circles\Db\CoreRequestBuilder::TABLE_CIRCLE, $aliasBasedOn, $expr->eq($aliasBasedOn . '.unique_id', $aliasMember . '.single_id'));
        if (!is_null($initiator)) {
            $this->leftJoinInitiator($aliasBasedOn, $initiator);
            $this->leftJoinOwner($aliasBasedOn);
        }
    }
    /**
     * @param string $alias
     * @param string $field
     *
     * @throws RequestBuilderException
     */
    public function leftJoinOwner(string $alias, string $field = 'unique_id'): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        try {
            $aliasMember = $this->generateAlias($alias, self::OWNER, $options);
            $getData = $this->getBool('getData', $options, false);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
            return;
        }
        $expr = $this->expr();
        $this->generateMemberSelectAlias($aliasMember)->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasMember, $expr->andX($expr->eq($aliasMember . '.circle_id', $alias . '.' . $field), $expr->eq($aliasMember . '.level', $this->createNamedParameter(\OCA\Circles\Model\Member::LEVEL_OWNER, self::PARAM_INT))));
        $this->leftJoinBasedOn($aliasMember);
    }
    /**
     * @throws RequestBuilderException
     */
    public function leftJoinCircleInvitation(string $alias, string $field = 'unique_id'): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        try {
            $aliasInvitation = $this->generateAlias($alias, self::INVITATION, $options);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException $e) {
            return;
        }
        $expr = $this->expr();
        $this->generateCircleInvitationSelectAlias($aliasInvitation)->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_INVITATIONS, $aliasInvitation, $expr->eq($aliasInvitation . '.circle_id', $alias . '.' . $field));
    }
    /**
     * @param CircleProbe $probe
     * @param string $alias
     * @param string $field
     */
    public function innerJoinMembership(?\OCA\Circles\Model\Probes\CircleProbe $probe, string $alias, string $field = 'unique_id'): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        try {
            $aliasMembership = $this->generateAlias($alias, self::MEMBERSHIPS);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
            return;
        }
        $expr = $this->expr();
        $on = $expr->andX($expr->eq($aliasMembership . '.circle_id', $alias . '.' . $field));
        // limit on membership level if requested
        $minLevel = $probe?->getMinimumLevel() ?? 0;
        if ($minLevel > \OCA\Circles\Model\Member::LEVEL_MEMBER) {
            $on->add($this->exprGt('level', $minLevel, true, $aliasMembership));
        }
        $this->generateMembershipSelectAlias($aliasMembership)->innerJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBERSHIP, $aliasMembership, $on);
    }
    /**
     * @param string $alias
     * @param string $fieldCircleId
     * @param string $fieldSingleId
     *
     * @throws RequestBuilderException
     */
    public function leftJoinMember(string $alias, string $fieldCircleId = 'circle_id', string $fieldSingleId = 'single_id'): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        try {
            $aliasMember = $this->generateAlias($alias, self::MEMBER, $options);
            $getData = $this->getBool('getData', $options, false);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
            return;
        }
        $expr = $this->expr();
        $this->generateMemberSelectAlias($aliasMember)->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasMember, $expr->andX($expr->eq($aliasMember . '.circle_id', $alias . '.' . $fieldCircleId), $expr->eq($aliasMember . '.single_id', $alias . '.' . $fieldSingleId), $this->exprGt('level', \OCA\Circles\Model\Member::LEVEL_MEMBER, true, $aliasMember)));
        $this->leftJoinRemoteInstance($aliasMember);
        $this->leftJoinBasedOn($aliasMember);
    }
    /**
     * if 'getData' is true, will returns 'inheritanceBy': the Member at the end of a sub-chain of
     * memberships (based on $field for Top Circle's singleId)
     *
     * @param string $alias
     * @param string $field
     * @param string $aliasInheritedBy
     *
     * @throws RequestBuilderException
     */
    public function leftJoinInheritedMembers(string $alias, string $field = '', string $aliasInheritedBy = ''): void
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        $field = $field === '' ? 'circle_id' : $field;
        $aliasMembership = $this->generateAlias($alias, self::MEMBERSHIPS, $options);
        $this->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBERSHIP, $aliasMembership, $expr->eq($aliasMembership . '.circle_id', $alias . '.' . $field));
        //		if (!$this->getBool('getData', $options, false)) {
        //			return;
        //		}
        if ($aliasInheritedBy === '') {
            $aliasInheritedBy = $this->generateAlias($alias, self::INHERITED_BY);
        }
        $this->generateMemberSelectAlias($aliasInheritedBy)->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasInheritedBy, $expr->andX($expr->eq($aliasMembership . '.inheritance_last', $aliasInheritedBy . '.circle_id'), $expr->eq($aliasMembership . '.single_id', $aliasInheritedBy . '.single_id')));
        $this->leftJoinBasedOn($aliasInheritedBy);
    }
    /**
     * @throws RequestBuilderException
     */
    public function limitToInheritedMemberships(string $alias, string $singleId, string $field = ''): void
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        $field = $field === '' ? 'circle_id' : $field;
        $aliasUpstreamMembership = $this->generateAlias($alias, self::UPSTREAM_MEMBERSHIPS, $options);
        $this->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBERSHIP, $aliasUpstreamMembership, $expr->eq($aliasUpstreamMembership . '.single_id', $this->createNamedParameter($singleId)));
        $orX = $expr->orX($expr->eq($aliasUpstreamMembership . '.circle_id', $alias . '.' . $field), $expr->eq($alias . '.' . $field, $this->createNamedParameter($singleId)));
        $this->andWhere($orX);
    }
    /**
     * limit the request to Members and Sub Members of a Circle.
     *
     * @param string $alias
     * @param string $singleId
     * @param int $level
     *
     * @throws RequestBuilderException
     */
    public function limitToMembersByInheritance(string $alias, string $singleId, int $level = 0): void
    {
        $this->__formsCircleLease->assertActive();
        $this->leftJoinMembersByInheritance($alias);
        $expr = $this->expr();
        $aliasMembership = $this->generateAlias($alias, self::MEMBERSHIPS);
        $this->andWhere($expr->eq($aliasMembership . '.circle_id', $this->createNamedParameter($singleId)));
        if ($level > 1) {
            $this->andWhere($expr->gte($aliasMembership . '.level', $this->createNamedParameter($level, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        }
    }
    /**
     * if 'getData' is true, will returns 'inheritanceFrom': the Circle-As-Member of the Top Circle
     * that explain the membership of a Member (based on $field for singleId) to a specific Circle
     *
     * // TODO: returns the link/path ?
     *
     * @param string $alias
     * @param string $field
     *
     * @throws RequestBuilderException
     */
    public function leftJoinMembersByInheritance(string $alias, string $field = ''): void
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        $field = $field === '' ? 'circle_id' : $field;
        $aliasMembership = $this->generateAlias($alias, self::MEMBERSHIPS, $options);
        $this->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBERSHIP, $aliasMembership, $expr->andX($expr->eq($aliasMembership . '.inheritance_last', $alias . '.' . $field), $expr->eq($aliasMembership . '.single_id', $alias . '.single_id')));
        if (!$this->getBool('getData', $options, false)) {
            return;
        }
        $aliasInheritanceFrom = $this->generateAlias($alias, self::INHERITANCE_FROM);
        $this->generateMemberSelectAlias($aliasInheritanceFrom)->leftJoin($aliasMembership, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasInheritanceFrom, $expr->andX($expr->eq($aliasMembership . '.circle_id', $aliasInheritanceFrom . '.circle_id'), $expr->eq($aliasMembership . '.inheritance_first', $aliasInheritanceFrom . '.single_id')));
    }
    /**
     * @param string $alias
     * @param string $token
     *
     * @throws RequestBuilderException
     */
    public function limitToShareToken(string $alias, string $token): void
    {
        $this->__formsCircleLease->assertActive();
        $this->leftJoinShareToken($alias);
        $aliasShareToken = $this->generateAlias($alias, self::TOKEN, $options);
        $this->generateSelectAlias(\OCA\Circles\Db\CoreRequestBuilder::$tables[\OCA\Circles\Db\CoreRequestBuilder::TABLE_TOKEN], $aliasShareToken, $aliasShareToken, []);
        $this->limit('token', $token, $aliasShareToken);
    }
    /**
     * @param string $alias
     * @param string $field
     *
     * @throws RequestBuilderException
     */
    public function leftJoinShareToken(string $alias, string $field = ''): void
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        $field = $field === '' ? 'id' : $field;
        $aliasShareToken = $this->generateAlias($alias, self::TOKEN, $options);
        $this->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_TOKEN, $aliasShareToken, $expr->eq($aliasShareToken . '.share_id', $alias . '.' . $field));
    }
    /**
     * limit the result to the point of view of a FederatedUser
     *
     * @param string $alias
     * @param IFederatedUser $user
     * @param string $field
     * @param string $helperAlias
     *
     * @return ICompositeExpression
     * @throws RequestBuilderException
     */
    public function limitToInitiator(string $alias, \OCA\Circles\IFederatedUser $user, string $field = '', string $helperAlias = ''): \OCP\DB\QueryBuilder\ICompositeExpression
    {
        $this->__formsCircleLease->assertActive();
        $this->leftJoinInitiator($alias, $user, $field, $helperAlias);
        $where = $this->limitInitiatorVisibility($alias);
        $aliasInitiator = $this->generateAlias($alias, self::INITIATOR, $options);
        if ($this->getBool('getData', $options, false)) {
            $this->leftJoinBasedOn($aliasInitiator);
        }
        return $where;
    }
    /**
     * @param string $alias
     */
    public function leftJoinCircleConfig(string $alias): void
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        try {
            $aliasConfig = $this->generateAlias($alias, self::CONFIG, $options);
            $this->selectAlias($aliasConfig . '.config', ($alias !== $this->getDefaultSelectAlias() ? $alias . '_' : '') . 'circle_config');
            $this->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_CIRCLE, $aliasConfig, $expr->eq($alias . '.circle_id', $aliasConfig . '.unique_id'));
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
        }
    }
    /**
     * Left join members to filter userId as initiator.
     *
     * @param string $alias
     * @param IFederatedUser $initiator
     * @param string $field
     * @param string $helperAlias
     *
     * @throws RequestBuilderException
     */
    public function leftJoinInitiator(string $alias, \OCA\Circles\IFederatedUser $initiator, string $field = '', string $helperAlias = ''): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        $expr = $this->expr();
        $field = $field === '' ? 'unique_id' : $field;
        $helperAlias = $helperAlias !== '' ? $helperAlias : $alias;
        $aliasMembership = $this->generateAlias($alias, self::MEMBERSHIPS, $options);
        $this->leftJoin($helperAlias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBERSHIP, $aliasMembership, $expr->andX($this->exprLimit('single_id', $initiator->getSingleId(), $aliasMembership), $expr->eq($aliasMembership . '.circle_id', $helperAlias . '.' . $field)));
        $listMembershipCircleAlias = [$aliasMembership];
        if ($this->getBool('initiatorDirectMember', $options, false)) {
            try {
                $aliasDirectInitiator = $this->generateAlias($alias, self::DIRECT_INITIATOR, $options);
                $listMembershipCircleAlias[] = $aliasDirectInitiator;
            } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
                // meaning that this path does not require DIRECT_INITIATOR; can be safely ignored
            }
        }
        try {
            $aliasMembershipCircle = $this->generateAlias($aliasMembership, self::CONFIG, $options);
            $orXMembershipCircle = $expr->orX(...array_map(fn(string $alias) => $this->expr()->eq($alias . '.circle_id', $aliasMembershipCircle . '.unique_id'), $listMembershipCircleAlias));
            $this->leftJoin($aliasMembership, \OCA\Circles\Db\CoreRequestBuilder::TABLE_CIRCLE, $aliasMembershipCircle, $orXMembershipCircle);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
            // meaning that this path (ie. self::$SQL_PATH) does not require CONFIG; can be safely ignored
        }
        if (!$this->getBool('getData', $options, false)) {
            return;
        }
        // bypass memberships
        if ($this->getBool('initiatorDirectMember', $options, false)) {
            try {
                $aliasDirectInitiator = $this->generateAlias($alias, self::DIRECT_INITIATOR, $options);
                $this->generateMemberSelectAlias($aliasDirectInitiator)->leftJoin($helperAlias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasDirectInitiator, $expr->andX($this->exprLimit('single_id', $initiator->getSingleId(), $aliasDirectInitiator), $expr->eq($aliasDirectInitiator . '.circle_id', $helperAlias . '.' . $field)));
            } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
                // meaning that this path does not require DIRECT_INITIATOR; can be safely ignored
            }
        }
        try {
            $aliasInitiator = $this->generateAlias($alias, self::INITIATOR, $options);
            $this->leftJoin($aliasMembership, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasInitiator, $expr->andX($expr->eq($aliasMembership . '.inheritance_first', $aliasInitiator . '.single_id'), $expr->eq($aliasMembership . '.circle_id', $aliasInitiator . '.circle_id')));
            $aliasInheritedBy = $this->generateAlias($aliasInitiator, self::INHERITED_BY);
            $this->leftJoin($aliasInitiator, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasInheritedBy, $expr->andX($expr->eq($aliasMembership . '.single_id', $aliasInheritedBy . '.single_id'), $expr->eq($aliasMembership . '.inheritance_last', $aliasInheritedBy . '.circle_id')));
            $default = [];
            if ($this->getBool('emulateVisitor', $options)) {
                $default = ['user_id' => $initiator->getUserId(), 'single_id' => $initiator->getSingleId(), 'user_type' => $initiator->getUserType(), 'cached_name' => $initiator->getDisplayName(), 'instance' => $initiator->getInstance()];
            }
            $aliasInheritedByMembership = $this->generateAlias($aliasInheritedBy, self::MEMBERSHIPS);
            $this->generateMemberSelectAlias($aliasInitiator, $default)->generateMemberSelectAlias($aliasInheritedBy)->generateMembershipSelectAlias($aliasMembership, $aliasInheritedByMembership);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
        }
    }
    public function completeProbeWithInitiator(string $alias, string $field = 'single_id', string $helperAlias = ''): ?string
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return null;
        }
        try {
            $aliasInitiator = $this->generateAlias($alias, self::INITIATOR);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException) {
            return null;
        }
        $helperAlias = $helperAlias === '' ? $alias : $helperAlias;
        $expr = $this->expr();
        $this->generateMemberSelectAlias($aliasInitiator)->leftJoin($alias, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER, $aliasInitiator, $expr->andX($expr->eq($aliasInitiator . '.circle_id', $alias . '.unique_id'), $expr->eq($aliasInitiator . '.' . $field, $helperAlias . '.inheritance_first')));
        return $aliasInitiator;
    }
    /**
     * @param string $alias
     *
     * @return ICompositeExpression
     * @throws RequestBuilderException
     */
    protected function limitInitiatorVisibility(string $alias): \OCP\DB\QueryBuilder\ICompositeExpression
    {
        $this->__formsCircleLease->assertActive();
        $aliasMembership = $this->generateAlias($alias, self::MEMBERSHIPS, $options);
        $aliasMembershipCircle = $this->generateAlias($aliasMembership, self::CONFIG, $options);
        $levelCheck = [$aliasMembership];
        // no need to check anything, we are filtering by invitation code
        $invitationCode = $this->get('filterInvitationCode', $options, '');
        if ($invitationCode) {
            return $this->expr()->andX($this->expr()->eq('1', '1'));
        }
        $directMember = '';
        if ($this->getBool('initiatorDirectMember', $options, false)) {
            $directMember = $this->generateAlias($alias, self::DIRECT_INITIATOR, $options);
            array_push($levelCheck, $directMember);
        }
        $expr = $this->expr();
        // Visibility to non-member is
        // - 0 (default), if initiator is member
        // - 2 (Personal), if initiator is owner)
        // - 4 (Visible to everyone)
        $orX = [];
        // filterPersonalCircles will remove access to Personal Circles as Owner
        if (!$this->getBool('filterPersonalCircles', $options, false)) {
            $orX[] = $expr->andX($this->exprLimitBitwise('config', \OCA\Circles\Model\Circle::CFG_PERSONAL, $aliasMembershipCircle), $expr->eq($aliasMembership . '.level', $this->createNamedParameter(\OCA\Circles\Model\Member::LEVEL_OWNER)));
        }
        $minimumLevel = $this->getInt('minimumLevel', $options);
        $orXLevelCheck = $expr->orX(...array_map(fn(string $alias) => $this->expr()->gte($alias . '.level', $this->createNamedParameter($minimumLevel, self::PARAM_INT)), $levelCheck));
        $andXMember = [$orXLevelCheck];
        if (!$this->getBool('includePersonalCircles', $options, false)) {
            $andXMember[] = $this->exprFilterBitwise('config', \OCA\Circles\Model\Circle::CFG_PERSONAL, $aliasMembershipCircle);
        }
        $orX[] = $expr->andX(...$andXMember);
        if ($directMember !== '' && $this->getBool('allowRequestingMembership', $options, false)) {
            $orX[] = $expr->orX($this->exprLimit('status', \OCA\Circles\Model\Member::STATUS_REQUEST, $directMember), $this->exprLimit('status', \OCA\Circles\Model\Member::STATUS_INVITED, $directMember));
        }
        if ($minimumLevel === 0 && $alias === self::CIRCLE) {
            $orX[] = $this->exprLimitBitwise('config', \OCA\Circles\Model\Circle::CFG_VISIBLE, $alias);
        }
        if ($this->getBool('includeNonVisibleCircles', $options)) {
            $orX[] = $expr->andX($this->exprLimitBitwise('config', \OCA\Circles\Model\Circle::CFG_OPEN, $alias), $this->exprFilterBitwise('config', \OCA\Circles\Model\Circle::CFG_VISIBLE, $alias));
        }
        if ($this->getBool('visitingSingleCircles', $options, false)) {
            $orX[] = $this->exprLimitBitwise('config', \OCA\Circles\Model\Circle::CFG_SINGLE, $alias);
        }
        // if Member can be Visitor, we only filter access to Personal Circles
        if ($this->getBool('viewableThroughKeyhole', $options, false)) {
            $andOpen = [$this->exprLimitBitwise('config', \OCA\Circles\Model\Circle::CFG_OPEN, $alias), $this->exprLimitBitwise('config', \OCA\Circles\Model\Circle::CFG_VISIBLE, $alias)];
            if (!$this->configService->getAppValueBool(\OCA\Circles\Service\ConfigService::KEYHOLE_CFG_REQUEST)) {
                $andOpen[] = $this->exprFilterBitwise('config', \OCA\Circles\Model\Circle::CFG_REQUEST, $alias);
            }
            $orX[] = $expr->andX(...$andOpen);
        }
        $orX = $expr->orX(...$orX);
        $this->andWhere($orX);
        return $orX;
    }
    public function filterInvitationCode(string $alias, string $invitationCode): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT) {
            return;
        }
        try {
            $aliasInvitation = $this->generateAlias($alias, self::INVITATION, $options);
        } catch (\OCA\Circles\Exceptions\RequestBuilderException $e) {
            return;
        }
        $expr = $this->expr();
        $this->andWhere($expr->eq($aliasInvitation . '.invitation_code', $this->createNamedParameter($invitationCode)));
    }
    /**
     * @param string $aliasCircle
     * @param CircleProbe $probe
     */
    public function filterCircles(string $aliasCircle, \OCA\Circles\Model\Probes\CircleProbe $probe): void
    {
        $this->__formsCircleLease->assertActive();
        $filter = $probe->filtered();
        $include = $probe->included();
        $config = ($filter | $include) - $include;
        $this->filterBitwise('config', $config, $aliasCircle);
    }
    /**
     * Limit visibility on Sensitive information when search for members.
     *
     * @param string $alias
     *
     * @return ICompositeExpression
     */
    private function limitRemoteVisibility_Sensitive_Members(string $alias): \OCP\DB\QueryBuilder\ICompositeExpression
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        return $expr->andX($expr->eq($alias . '.type', $this->createNamedParameter(\OCA\Circles\Model\Federated\RemoteInstance::TYPE_PASSIVE)), $expr->orX(
            $expr->eq($this->getDefaultSelectAlias() . '.instance', $alias . '.instance'),
            // TODO: do we need this ? (display members from the local instance)
            $expr->emptyString($this->getDefaultSelectAlias() . '.instance'),
            $expr->eq($this->getDefaultSelectAlias() . '.level', $this->createNamedParameter(\OCA\Circles\Model\Member::LEVEL_OWNER))
        ));
    }
    /**
     * Link to storage/filecache
     *
     * @param string $aliasShare
     *
     * @throws RequestBuilderException
     */
    public function leftJoinFileCache(string $aliasShare)
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        $aliasFileCache = $this->generateAlias($aliasShare, self::FILE_CACHE);
        $aliasStorages = $this->generateAlias($aliasFileCache, self::STORAGES);
        $this->generateSelectAlias(\OCA\Circles\Db\CoreRequestBuilder::$outsideTables[\OCA\Circles\Db\CoreRequestBuilder::TABLE_FILE_CACHE], $aliasFileCache, $aliasFileCache, [])->generateSelectAlias(\OCA\Circles\Db\CoreRequestBuilder::$outsideTables[\OCA\Circles\Db\CoreRequestBuilder::TABLE_STORAGES], $aliasStorages, $aliasStorages, [])->leftJoin($aliasShare, \OCA\Circles\Db\CoreRequestBuilder::TABLE_FILE_CACHE, $aliasFileCache, $expr->eq($aliasShare . '.file_source', $aliasFileCache . '.fileid'))->leftJoin($aliasFileCache, \OCA\Circles\Db\CoreRequestBuilder::TABLE_STORAGES, $aliasStorages, $expr->eq($aliasFileCache . '.storage', $aliasStorages . '.numeric_id'));
    }
    /**
     * @param string $aliasShare
     * @param string $aliasShareMemberships
     *
     * @return string the alias generated for the join
     *
     * @throws RequestBuilderException
     */
    public function leftJoinShareChild(string $aliasShare, string $aliasShareMemberships = ''): string
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        $aliasShareChild = $this->generateAlias($aliasShare, self::SHARE);
        if ($aliasShareMemberships === '') {
            $aliasShareMemberships = $this->generateAlias($aliasShare, self::MEMBERSHIPS, $options);
        }
        $this->leftJoin($aliasShareMemberships, \OCA\Circles\Db\CoreRequestBuilder::TABLE_SHARE, $aliasShareChild, $expr->andX($expr->eq($aliasShareChild . '.parent', $aliasShare . '.id'), $expr->eq($aliasShareChild . '.share_with', $aliasShareMemberships . '.single_id')));
        $this->generateSelectAlias(['id', 'file_target', 'permissions'], $aliasShareChild, 'child', []);
        //		$this->selectAlias($aliasShareParent . '.permissions', 'parent_perms');
        return $aliasShareChild;
    }
    /**
     * @param string $alias
     * @param FederatedUser $federatedUser
     * @param bool $reshares
     */
    public function limitToShareOwner(string $alias, \OCA\Circles\Model\FederatedUser $federatedUser, bool $reshares, int $nodeId = 0): void
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        if ($reshares === false) {
            $this->andWhere($this->exprLimit('uid_initiator', $federatedUser->getUserId(), $alias));
        } elseif ($nodeId === 0) {
            $this->andWhere($expr->orX($this->exprLimit('uid_owner', $federatedUser->getUserId(), $alias), $this->exprLimit('uid_initiator', $federatedUser->getUserId(), $alias)));
        }
    }
    /**
     * @param string $aliasMount
     * @param string $aliasMountMemberships
     *
     * @throws RequestBuilderException
     */
    public function leftJoinMountpoint(string $aliasMount, \OCA\Circles\IFederatedUser $federatedUser, string $aliasMountMemberships = '')
    {
        $this->__formsCircleLease->assertActive();
        $expr = $this->expr();
        $aliasMountpoint = $this->generateAlias($aliasMount, self::MOUNTPOINT);
        if ($aliasMountMemberships === '') {
            $aliasMountMemberships = $this->generateAlias($aliasMount, self::MEMBERSHIPS, $options);
        }
        $this->leftJoin($aliasMountMemberships, \OCA\Circles\Db\CoreRequestBuilder::TABLE_MOUNTPOINT, $aliasMountpoint, $expr->andX($expr->eq($aliasMountpoint . '.mount_id', $aliasMount . '.mount_id'), $expr->eq($aliasMountpoint . '.single_id', $this->createNamedParameter($federatedUser->getSingleId()))));
        $this->selectAlias($aliasMountpoint . '.mountpoint', $aliasMountpoint . '_mountpoint');
        $this->selectAlias($aliasMountpoint . '.mountpoint_hash', $aliasMountpoint . '_mountpoint_hash');
    }
    /**
     * @param list<string> $paths
     */
    public function limitToMountpoints(string $aliasMount, array $paths, bool $forChildren = false): void
    {
        $this->__formsCircleLease->assertActive();
        if (count($paths) === 0) {
            return;
        }
        $expr = $this->expr();
        $orX = $expr->orX();
        if ($forChildren) {
            foreach ($paths as $path) {
                $orX->add($expr->like($aliasMount . '.mountpoint', $this->createNamedParameter($path . '/_%')));
            }
        } else {
            $pathChunks = array_chunk($paths, 1000);
            foreach ($pathChunks as $pathChunk) {
                $orX->add($expr->in($aliasMount . '.mountpoint', $this->createNamedParameter($pathChunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
            }
        }
        $this->andWhere($orX);
    }
    /**
     * @param string $alias
     * @param array $default
     *
     * @return CoreQueryBuilder&IQueryBuilder
     */
    private function generateCircleSelectAlias(string $alias, array $default = []): self
    {
        $this->__formsCircleLease->assertActive();
        $this->generateSelectAlias(\OCA\Circles\Db\CoreRequestBuilder::$tables[\OCA\Circles\Db\CoreRequestBuilder::TABLE_CIRCLE], $alias, $alias, $default);
        return $this;
    }
    private function generateCircleInvitationSelectAlias(string $alias, array $default = []): self
    {
        $this->__formsCircleLease->assertActive();
        $this->generateSelectAlias(\OCA\Circles\Db\CoreRequestBuilder::$tables[\OCA\Circles\Db\CoreRequestBuilder::TABLE_INVITATIONS], $alias, $alias, $default);
        return $this;
    }
    /**
     * @param string $alias
     * @param array $default
     *
     * @return $this
     */
    private function generateMemberSelectAlias(string $alias, array $default = []): self
    {
        $this->__formsCircleLease->assertActive();
        $this->generateSelectAlias(\OCA\Circles\Db\CoreRequestBuilder::$tables[\OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER], $alias, $alias, $default);
        return $this;
    }
    /**
     * @param string $alias
     * @param array $default
     * @param string $prefix
     *
     * @return $this
     */
    private function generateMembershipSelectAlias(string $alias, string $prefix = '', array $default = []): self
    {
        $this->__formsCircleLease->assertActive();
        $this->generateSelectAlias(\OCA\Circles\Db\CoreRequestBuilder::$tables[\OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBERSHIP], $alias, $prefix === '' ? $alias : $prefix, $default);
        return $this;
    }
    /**
     * @param string $alias
     * @param array $default
     *
     * @return $this
     */
    private function generateRemoteInstanceSelectAlias(string $alias, array $default = []): self
    {
        $this->__formsCircleLease->assertActive();
        $this->generateSelectAlias(\OCA\Circles\Db\CoreRequestBuilder::$tables[\OCA\Circles\Db\CoreRequestBuilder::TABLE_REMOTE], $alias, $alias, $default);
        return $this;
    }
    /**
     * @param array $path
     * @param array $options
     *
     * @return CoreQueryBuilder&IQueryBuilder
     */
    public function setOptions(array $path, array $options): self
    {
        $this->__formsCircleLease->assertActive();
        $options = [self::OPTIONS => $options];
        foreach (array_reverse($path) as $item) {
            $options = [$item => $options];
        }
        $this->options = array_merge_recursive($this->options, $options);
        return $this;
    }
    /**
     * @param string $base
     * @param string $extension
     * @param array|null $options
     *
     * @return string
     * @throws RequestBuilderException
     */
    public function generateAlias(string $base, string $extension, ?array &$options = []): string
    {
        $this->__formsCircleLease->assertActive();
        $search = str_replace('_', '.', $base);
        $path = $search . '.' . $extension;
        if (!$this->validKey($path, $this->getSqlPath()) && !in_array($extension, $this->getArray($search, $this->getSqlPath()))) {
            throw new \OCA\Circles\Exceptions\RequestBuilderException($extension . ' not found in ' . $search);
        }
        if (!is_array($options)) {
            $options = [];
        }
        $optionPath = '';
        foreach (explode('.', $path) as $p) {
            $optionPath = trim($optionPath . '.' . $p, '.');
            $options = array_merge($options, $this->getArray($optionPath . '.' . self::OPTIONS, $this->getSqlPath()), $this->getArray($optionPath . '.' . self::OPTIONS, $this->options));
        }
        return $base . '_' . $extension;
    }
    /**
     * @param string $prefix
     *
     * @return array
     */
    public function getAvailablePath(string $prefix): array
    {
        $this->__formsCircleLease->assertActive();
        $prefix = trim($prefix, '_');
        $search = str_replace('_', '.', $prefix);
        $path = [];
        foreach ($this->getArray($search, $this->getSqlPath()) as $arr => $item) {
            if (is_numeric($arr)) {
                $k = $item;
            } else {
                $k = $arr;
            }
            $path[$k] = $prefix . '_' . $k . '_';
        }
        return $path;
    }
    /**
     * @return array
     */
    public function getSqlPath(): array
    {
        $this->__formsCircleLease->assertActive();
        if (empty($this->sqlPath)) {
            return self::$SQL_PATH;
        }
        return $this->sqlPath;
    }
    /**
     * DataProbe uses this to set which data need to be extracted, based on self::$SQL_PATH.
     *
     * @param string $key
     * @param array $path
     *
     * @return $this
     */
    public function setSqlPath(string $key, array $path = []): self
    {
        $this->__formsCircleLease->assertActive();
        if (empty($this->sqlPath)) {
            $this->sqlPath = self::$SQL_PATH;
        }
        $this->sqlPath[$key] = $path;
        return $this;
    }
    public function resetSqlPath(): self
    {
        $this->__formsCircleLease->assertActive();
        $this->sqlPath = [];
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
public function getConnection() {throw new \RuntimeException('Circle database connection cannot escape the read graph');}
public function getLastInsertId(): int {throw new \RuntimeException('Circle read graph has no insertion state');}
/** Raw results and alternate connections cannot escape the owned bounded reader. */
public function executeQuery(?\OCP\IDBConnection $connection = null): \OCP\DB\IResult {throw new \RuntimeException('Circle raw query execution is not qualified');}
public function executeStatement(?\OCP\IDBConnection $connection = null): int {throw new \RuntimeException('Circle read graph cannot persist');}
/** Execute only after the owned projection/predicate validators, through the lease connection. */
private function executeOwnedSelect(): \OCP\DB\IResult {
    $this->__formsCircleLease->checkpoint();
    if($this->getType()!==\Doctrine\DBAL\Query\QueryBuilder::SELECT){throw new \RuntimeException('Circle owned reader accepts only SELECT');}
    $sql=$this->getSQL();$params=$this->getParameters();$types=$this->getParameterTypes();
    return $this->__formsCircleLease->db()->executeQuery($sql,$params,$types);
}
public function asItem(string $object,array $params=[]): \OCA\Circles\Tools\Db\IQueryRow {
    $rows=$this->rawPage(2);
    if(count($rows)===0){throw new \OCA\Circles\Tools\Exceptions\RowNotFoundException();}
    if(count($rows)!==1){throw new \RuntimeException('Circle query identity is ambiguous');}
    return $this->parseOwned($rows[0],$object,$params);
}
public function asItems(string $object,array $params=[]): array {
    $raw=$this->rawPage(101);$parsed=[];
    foreach($raw as $row){$parsed[]=$this->parseOwned($row,$object,$params);}
    return $parsed;
}
public function getRow(callable $method,string $object,array $params=[]): \OCA\Circles\Tools\Db\IQueryRow {throw new \RuntimeException('Custom Circle parser is not qualified');}
public function getRows(callable $method,string $object,array $params=[]): array {throw new \RuntimeException('Custom Circle parser is not qualified');}
/** Execute only a fresh native inherited-members query; caller mutations cannot affect it. */
public function readInheritedUserIds(string $singleId): array {
    $this->__formsCircleLease->assertActive();
    $query = $this->__formsCircleLease->members()->inheritedUserQuery($singleId);
    return $query->rawInheritedUsers();
}
private function rawInheritedUsers(): array {
    $this->__formsCircleLease->checkpoint();
    $expected = [];
    foreach (\OCA\Circles\Db\CoreRequestBuilder::$tables[\OCA\Circles\Db\CoreRequestBuilder::TABLE_MEMBER] as $field) {
        $expected[] = [self::MEMBER . '.' . $field, $field, $field];
    }
    if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT
        || $this->ownedProjection !== $expected
        || count($this->getQueryPart('select')) !== count($expected)
        || $this->getMaxResults() !== null || $this->getFirstResult() !== 0) {
        throw new \RuntimeException('Circle user projection query is not canonical');
    }
    // Only output columns/limit change. Native joins, bindings, predicates and ordering stay intact.
    $this->select([
        $this->createFunction('SUBSTRING(' . self::MEMBER . '.single_id,1,32) AS single_id'),
        $this->createFunction('SUBSTRING(' . self::MEMBER . '.user_id,1,257) AS user_id'),
        $this->createFunction(self::MEMBER . '.user_type AS user_type'),
    ]);
    $this->setMaxResults(UserProjection::MAX_ROWS + 1);
    $result = $this->executeOwnedSelect();
    $users = UserProjection::consume(
        static fn(): array|false => $result->fetchAssociative(),
        fn() => $this->__formsCircleLease->assertActive(),
        static fn() => $result->closeCursor()
    );
    // Cursor is already closed, including all error paths. Re-check fresh authority before returning data.
    $this->__formsCircleLease->checkpoint();
    return $users;
}
/** Only a fresh native team query can enter the bounded scalar reader. */
public function readUserTeamIds(string $uid): array {
    $this->__formsCircleLease->assertActive();
    $query = $this->__formsCircleLease->circles()->userTeamQuery($uid);
    return $query->rawTeamIds();
}
private function rawTeamIds(): array {
    $this->__formsCircleLease->checkpoint();
    $base = [];
    foreach (\OCA\Circles\Db\CoreRequestBuilder::$tables[\OCA\Circles\Db\CoreRequestBuilder::TABLE_CIRCLE] as $field) {
        $base[] = [self::CIRCLE . '.' . $field, $field, $field];
    }
    if ($this->getType() !== \Doctrine\DBAL\Query\QueryBuilder::SELECT
        || array_slice($this->ownedProjection, 0, count($base)) !== $base
        || count($this->getQueryPart('select')) !== count($this->ownedProjection)
        || $this->getMaxResults() !== null || $this->getFirstResult() !== 0) {
        throw new \RuntimeException('Circle team projection query is not canonical');
    }
    // Preserve every native join, authorization predicate, binding and order. No DISTINCT or OFFSET pages.
    // The extra character detects malformed IDs instead of returning a silently truncated identifier.
    $this->select([$this->createFunction('SUBSTRING(' . self::CIRCLE . '.unique_id,1,32) AS unique_id')]);
    $this->setMaxResults(TeamProjection::MAX_ROWS + 1);
    $result = $this->executeOwnedSelect();
    $ids = TeamProjection::consume(
        static fn(): array|false => $result->fetchAssociative(),
        fn() => $this->__formsCircleLease->assertActive(),
        static fn() => $result->closeCursor()
    );
    // consume closes even on fetch/deadline/parse failure. Fresh authority is checked after close.
    $this->__formsCircleLease->checkpoint();
    return $ids;
}
private function rawPage(int $bound): array {
    $this->__formsCircleLease->checkpoint();
    if($this->getType()!==\Doctrine\DBAL\Query\QueryBuilder::SELECT){throw new \RuntimeException('Circle graph accepts only SELECT');}
    $limit=$this->getMaxResults();
    $this->setMaxResults($limit===null? $bound:min($limit,$bound));
    $this->boundProjection();$result=$this->executeOwnedSelect();$rows=[];
    try {while(($row=$result->fetchAssociative())!==false){$rows[]=$row;\OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate($rows);if(count($rows)>100){throw new \RuntimeException('Circle row budget exceeded');}}}
    finally {$result->closeCursor();}
    \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate($rows);
    $this->__formsCircleLease->checkpoint();
    return $rows;
}
private function parseOwned(array $data,string $object,array $params): \OCA\Circles\Tools\Db\IQueryRow {
    if(isset($params['modelFromField'])){throw new \RuntimeException('Dynamic Circle model field is not qualified');}
    if($params!==[]){$data['_params']=$params;}
    foreach($this->getDefaultValues() as $k=>$v){if($this->get($k,$data)===''){$data[$k]=$v;}}
    $data=array_merge($this->getDefaultValues(),$data);
    \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
    $model=$this->__formsCircleLease->model($object);
    if(!$model instanceof \OCA\Circles\Tools\Db\IQueryRow){throw new \RuntimeException('Circle model is not a query row');}
    $model->importFromDatabase($data);
    return $model;
}
protected function getList(string $k,array $arr,array $import,array $default=[]): array {
    $this->__formsCircleLease->assertActive();
    if(count($import)!==2||!is_string($import[0])||$import[1]!=='import'){throw new \RuntimeException('Circle import method is not qualified');}
    $rows=$this->getArray($k,$arr,$default);
    \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate($rows);
    $out=[];foreach($rows as $row){$model=$this->__formsCircleLease->model($import[0]);$model->import($row);$out[]=$model;}return $out;
}

private array $ownedProjection=[];
private const UNBOUNDED_TEXT=['settings','description','note','contact_meta','inheritance_path','href','item','attributes'];
public function generateSelect(string $table,array $fields,string $alias=''): self {
    $this->ownedProjection=[];
    foreach($fields as $field){$this->projectionField($alias,$field,$field);}
    parent::generateSelect($table,$fields,$alias);
    return $this;
}
public function generateSelectAlias(array $fields,string $alias,string $prefix,array $default=[]): self {
    foreach($fields as $field){$this->projectionField($alias,$field,trim($prefix).'_'.$field);}
    parent::generateSelectAlias($fields,$alias,$prefix,$default);
    return $this;
}
private function projectionField(string $alias,string $field,string $output): void {
    foreach([$alias,$field,$output]as$name){if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$name)){throw new \RuntimeException('Circle projection identifier is not qualified');}}
    if(count($this->ownedProjection)>=128){throw new \RuntimeException('Circle projection column budget exceeded');}
    $this->ownedProjection[]=[$alias.'.'.$field,$field,$output];
}
private function boundProjection(): void {
    if(count($this->getQueryPart('select'))!==count($this->ownedProjection)){throw new \RuntimeException('Circle projection was replaced outside the native factory');}
    $large=array_values(array_filter($this->ownedProjection,static fn(array $p):bool=>in_array($p[1],self::UNBOUNDED_TEXT,true)));
    $sum=$large===[]?'0':implode('+',array_map(static fn(array $p):string=>'OCTET_LENGTH(COALESCE('.$p[0].",''))",$large));
    $select=[];
    foreach($this->ownedProjection as[$column,$field,$output]){
        $expression=in_array($field,self::UNBOUNDED_TEXT,true)?"CASE WHEN ($sum)>1000000 THEN '__forms_circle_row_budget_exceeded__' ELSE $column END":$column;
        $select[]=$this->createFunction($expression.' AS '.$output);
    }
    $this->select($select);
}
}
