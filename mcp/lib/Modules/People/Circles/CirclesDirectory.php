<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\People\Circles;
use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Profile\IProfileManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/** Actor-context reader for Circles (Nextcloud Teams), never a privileged expansion. */
final class CirclesDirectory {
    public const MAX_TEAMS = 50;
    public const MAX_MEMBERS = 100;
    public const MAX_MEMBER_SCAN = 500;
    private const TYPE_USER = 1;
    private const TYPE_GROUP = 2;
    private const TYPE_MAIL = 4;
    private const TYPE_CONTACT = 8;
    private const TYPE_CIRCLE = 16;
    private const TYPE_APP = 10000;

    /** @param \Closure():object $manager @param \Closure():object $probe @param \Closure():object $dataProbe */
    public function __construct(private IAppManager $apps, private IUserManager $users, private IUserSession $session,
        private \Closure $manager, private \Closure $probe, private \Closure $dataProbe, private LoggerInterface $logger,
        private ?IProfileManager $profiles = null, private ?PlanState $planState = null, private ?ISearch $search = null) {}

    /** @return array{teams:list<array{id:string,name:string,displayName:string}>,truncated:bool} */
    public function listTeams(string $actorId, string $query, int $limit): array {
        $query = trim($query);
        if (mb_strlen($query) > 100 || ($query !== '' && mb_strlen($query) < 2) || $limit < 1 || $limit > self::MAX_TEAMS) {
            throw new InvalidArgumentException('Invalid team search');
        }
        return $this->withActor($actorId, function (object $manager) use ($query, $limit): array {
            $probe = $this->visibleProbe();
            if (!method_exists($manager, 'probeCircles')) { throw new \RuntimeException('Circles probe API unavailable'); }
            $circles = $manager->probeCircles($probe);
            if (!is_array($circles)) { throw new \RuntimeException('Invalid native Circles result'); }
            $teams = [];
            foreach ($circles as $circle) {
                if (!is_object($circle) || !method_exists($circle, 'getSingleId') || !method_exists($circle, 'getName') || !method_exists($circle, 'getDisplayName')) {
                    throw new \RuntimeException('Invalid native team');
                }
                $id = (string)$circle->getSingleId();
                $name = trim((string)$circle->getName());
                $displayName = trim((string)$circle->getDisplayName());
                if ($id === '' || $name === '' || $displayName === '') { continue; }
                if ($query !== '' && mb_stripos($name . ' ' . $displayName, $query) === false) { continue; }
                $teams[$id] = ['id'=>$id,'name'=>mb_substr($name,0,255),'displayName'=>mb_substr($displayName,0,255)];
            }
            $items = array_values($teams);
            return ['teams'=>array_slice($items,0,$limit),'truncated'=>count($items)>$limit];
        });
    }

    /** @return array{team:array{id:string,name:string,displayName:string},members:list<array<string,mixed>>,truncated:bool} */
    public function listMembers(string $actorId, string $circleId, int $limit): array {
        if ($circleId === '' || strlen($circleId) > 255 || preg_match('/[\x00-\x1f\x7f]/', $circleId) === 1 || $limit < 1 || $limit > self::MAX_MEMBERS) {
            throw new InvalidArgumentException('Invalid team reference');
        }
        return $this->withActor($actorId, function (object $manager) use ($circleId, $limit): array {
            $probe = $this->visibleProbe();
            if (!method_exists($manager, 'probeCircle')) { throw new \RuntimeException('Circles team probe API unavailable'); }
            $dataProbe = ($this->dataProbe)();
            if (!is_object($dataProbe)) { throw new \RuntimeException('Circles member data probe unavailable'); }
            $circle = $manager->probeCircle($circleId, $probe, $dataProbe);
            foreach (['getSingleId','getName','getDisplayName','isLocal','getMembers'] as $method) {
                if (!is_object($circle) || !method_exists($circle, $method)) { throw new \RuntimeException('Invalid native team'); }
            }
            if (!$circle->isLocal() || (string)$circle->getSingleId() !== $circleId) { throw new \RuntimeException('Unsupported native team'); }
            $members = $circle->getMembers();
            if (!is_array($members)) { throw new \RuntimeException('Invalid native membership'); }
            $rows = [];
            foreach ($members as $member) {
                $row = $this->member($member);
                if ($row !== null) { $rows[] = $row; }
            }
            return ['team'=>['id'=>$circleId,'name'=>mb_substr(trim((string)$circle->getName()),0,255),
                'displayName'=>mb_substr(trim((string)$circle->getDisplayName()),0,255)],'members'=>array_slice($rows,0,$limit),'truncated'=>count($rows)>$limit];
        });
    }

    /** Filter local visible team members by profile fields the active actor may see. */
    public function filterMembers(string $actorId, string $circleId, string $organisation, string $professionalRole, int $limit): array {
        $this->validateCircleId($circleId);
        $organisation = trim($organisation);
        $professionalRole = trim($professionalRole);
        foreach ([$organisation, $professionalRole] as $filter) {
            if ($filter !== '' && (mb_strlen($filter) < 2 || mb_strlen($filter) > 100)) { throw new InvalidArgumentException('Invalid profile filter'); }
        }
        if ($limit < 1 || $limit > self::MAX_MEMBERS) { throw new InvalidArgumentException('Invalid member limit'); }
        if (!$this->profiles instanceof IProfileManager) { throw new ToolFailure(Translator::t('The team directory is unavailable.')); }

        return $this->withActor($actorId, function (object $manager) use ($actorId, $circleId, $organisation, $professionalRole, $limit): array {
            [$circle, $members] = $this->nativeTeam($manager, $circleId);
            $actor = $this->session->getUser();
            if (!$actor instanceof IUser) { throw new ToolFailure(Translator::t('The team directory is unavailable.')); }
            $scanned = min(count($members), self::MAX_MEMBER_SCAN);
            $truncated = count($members) > self::MAX_MEMBER_SCAN;
            $rows = [];
            foreach (array_slice($members, 0, self::MAX_MEMBER_SCAN) as $member) {
                $base = $this->member($member);
                if (($base['type'] ?? null) !== 'user' || !is_string($base['uid'] ?? null)) { continue; }
                $profile = $this->visibleMemberProfile($base['uid'], $actor);
                $org = $profile['organisation'] ?? null;
                $role = $profile['role'] ?? null;
                if ($organisation !== '' && (!is_string($org) || mb_stripos($org, $organisation) === false)) { continue; }
                if ($professionalRole !== '' && (!is_string($role) || mb_stripos($role, $professionalRole) === false)) { continue; }
                $row = ['type'=>'user','uid'=>$base['uid'],'displayName'=>$base['displayName'],'teamRole'=>$base['role']];
                if (is_string($org)) { $row['organisation'] = $org; }
                if (is_string($role)) { $row['professionalRole'] = $role; }
                if (count($rows) < $limit) { $rows[] = $row; } else { $truncated = true; }
            }
            return ['team'=>['id'=>$circleId,'name'=>mb_substr(trim((string)$circle->getName()),0,255),
                'displayName'=>mb_substr(trim((string)$circle->getDisplayName()),0,255)],
                'filters'=>['organisation'=>$organisation,'professional_role'=>$professionalRole],
                'members'=>$rows,'scanned'=>$scanned,'truncated'=>$truncated];
        });
    }

    /** @param array<string,mixed> $arguments */
    public function previewAddMember(array $arguments, string $actorId, string $tool): array {
        $circleId = $this->argumentCircleId($arguments);
        $uid = $this->argumentUserId($arguments);
        return $this->withActor($actorId, fn (object $manager): array => $this->memberChangePlan($manager, $circleId, $uid, $actorId, $tool, 'add'));
    }

    /** @param array<string,mixed> $arguments */
    public function addMember(array $arguments, string $actorId, string $tool): array {
        PlanState::require($arguments);
        $circleId = $this->argumentCircleId($arguments);
        $uid = $this->argumentUserId($arguments);
        if (!$this->planState instanceof PlanState) { throw new ToolFailure(Translator::t('The team directory is unavailable.')); }
        return $this->withActor($actorId, function (object $manager) use ($arguments, $circleId, $uid, $actorId, $tool): array {
            $plan = $this->memberChangePlan($manager, $circleId, $uid, $actorId, $tool, 'add');
            if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) { throw new PlanChanged($plan); }
            try {
                $member = $manager->addMember($circleId, $manager->getLocalFederatedUser($uid));
                $status = method_exists($member, 'getStatus') ? (string)$member->getStatus() : 'Member';
                return ['team'=>$plan['team'],'member'=>['uid'=>$uid,'teamRole'=>'member'],
                    'status'=>$status,'message'=>$status === 'Invited'
                        ? Translator::t('The member was invited; team access starts after the invitation is accepted.')
                        : Translator::t('The member was added and may gain access to content shared with this team.')];
            } catch (Throwable $e) {
                throw new ToolFailure(Translator::t('The team member could not be added.'), 0, $e);
            }
        }, syncMembership: true);
    }

    /** @param array<string,mixed> $arguments */
    public function previewRemoveMember(array $arguments, string $actorId, string $tool): array {
        $circleId = $this->argumentCircleId($arguments);
        $uid = $this->argumentUserId($arguments);
        $plan = $this->withActor($actorId, fn (object $manager): array => $this->memberChangePlan($manager, $circleId, $uid, $actorId, $tool, 'remove'));
        unset($plan['_nativeMemberId']);
        return $plan;
    }

    /** @param array<string,mixed> $arguments */
    public function removeMember(array $arguments, string $actorId, string $tool): array {
        PlanState::require($arguments);
        $circleId = $this->argumentCircleId($arguments);
        $uid = $this->argumentUserId($arguments);
        if (!$this->planState instanceof PlanState) { throw new ToolFailure(Translator::t('The team directory is unavailable.')); }
        return $this->withActor($actorId, function (object $manager) use ($arguments, $circleId, $uid, $actorId, $tool): array {
            $plan = $this->memberChangePlan($manager, $circleId, $uid, $actorId, $tool, 'remove');
            $nativeMemberId = $plan['_nativeMemberId'] ?? null;
            unset($plan['_nativeMemberId']);
            if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) { throw new PlanChanged($plan); }
            try {
                if (!is_string($nativeMemberId) || $nativeMemberId === '') { throw new \RuntimeException('Native team member id missing'); }
                $manager->removeMember($nativeMemberId);
                return ['team'=>$plan['team'],'removed'=>['uid'=>$uid],
                    'message'=>Translator::t('The member was removed and may lose access to content shared with this team.')];
            } catch (Throwable $e) {
                throw new ToolFailure(Translator::t('The team member could not be removed.'), 0, $e);
            }
        }, syncMembership: true);
    }

    /** @return array{object,list<mixed>} */
    private function nativeTeam(object $manager, string $circleId): array {
        if (!method_exists($manager, 'probeCircle')) { throw new \RuntimeException('Circles team probe API unavailable'); }
        $dataProbe = ($this->dataProbe)();
        if (!is_object($dataProbe)) { throw new \RuntimeException('Circles member data probe unavailable'); }
        $circle = $manager->probeCircle($circleId, $this->visibleProbe(), $dataProbe);
        foreach (['getSingleId','getName','getDisplayName','isLocal','getMembers'] as $method) {
            if (!is_object($circle) || !method_exists($circle, $method)) { throw new \RuntimeException('Invalid native team'); }
        }
        if (!$circle->isLocal() || (string)$circle->getSingleId() !== $circleId) { throw new ToolFailure(ToolFailure::NOT_FOUND); }
        $members = $circle->getMembers();
        if (!is_array($members)) { throw new \RuntimeException('Invalid native membership'); }
        return [$circle, $members];
    }

    /** @return array<string,mixed> */
    private function visibleMemberProfile(string $uid, IUser $actor): array {
        $user = $this->users->get($uid);
        if (!$user instanceof IUser || !$user->isEnabled() || !$this->profiles instanceof IProfileManager) { return []; }
        try {
            if (!$this->profiles->isProfileEnabled($user)) { return []; }
            $visible = $this->profiles->getProfileFields($user, $actor);
            $out = [];
            foreach (['organisation','role'] as $field) {
                $value = $visible[$field] ?? null;
                if (is_string($value) && trim($value) !== '') { $out[$field] = mb_substr(trim($value), 0, 255); }
            }
            return $out;
        } catch (Throwable $e) {
            $this->logger->debug('MCP visible team profile read failed', ['app'=>'mcp','exception_class'=>$e::class]);
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function memberChangePlan(object $manager, string $circleId, string $uid, string $actorId, string $tool, string $action): array {
        if (!$this->planState instanceof PlanState || !method_exists($manager, 'addMember') || !method_exists($manager, 'removeMember') || !method_exists($manager, 'getLocalFederatedUser')) {
            throw new ToolFailure(Translator::t('The team directory is unavailable.'));
        }
        [$circle, $members] = $this->nativeTeam($manager, $circleId);
        if ($action === 'add') { $this->requireVisibleAccount($uid); }
        $target = $this->users->get($uid);
        if (!$target instanceof IUser || !$target->isEnabled() || $target->getUID() !== $uid) { throw new ToolFailure(ToolFailure::NOT_FOUND); }
        if (!method_exists($circle, 'getInitiator')) { throw new \RuntimeException('Native actor membership unavailable'); }
        $initiator = $circle->getInitiator();
        if (!is_object($initiator) || !method_exists($initiator, 'getLevel')) { throw new ToolFailure(ToolFailure::FORBIDDEN); }
        $actorLevel = (int)$initiator->getLevel();
        $targetMember = null;
        foreach ($members as $candidate) {
            if (!is_object($candidate) || !method_exists($candidate, 'getUserType') || !method_exists($candidate, 'getUserId') || !method_exists($candidate, 'isLocal')) { continue; }
            if ((int)$candidate->getUserType() === self::TYPE_USER && $candidate->isLocal() && (string)$candidate->getUserId() === $uid) { $targetMember = $candidate; break; }
        }
        if ($action === 'add') {
            $friendInvite = method_exists($circle, 'isConfig') && $circle->isConfig(128);
            if ($actorLevel < 4 && !$friendInvite) { throw new ToolFailure(ToolFailure::FORBIDDEN); }
            if ($targetMember !== null) { throw new ToolFailure('This user is already a direct team member.'); }
            $memberState = null;
        } else {
            if ($targetMember === null) { throw new ToolFailure(ToolFailure::NOT_FOUND); }
            foreach (['getId','getLevel','getDisplayName'] as $method) { if (!method_exists($targetMember, $method)) { throw new \RuntimeException('Invalid native member'); } }
            $targetLevel = (int)$targetMember->getLevel();
            if ($actorLevel < 4 || $actorLevel <= $targetLevel || $targetLevel === 9) { throw new ToolFailure(ToolFailure::FORBIDDEN); }
            $memberState = ['id'=>(string)$targetMember->getId(),'level'=>$targetLevel,'status'=>method_exists($targetMember,'getStatus')?(string)$targetMember->getStatus():''];
        }
        $team = ['id'=>$circleId,'name'=>mb_substr(trim((string)$circle->getName()),0,255),'displayName'=>mb_substr(trim((string)$circle->getDisplayName()),0,255)];
        $member = ['uid'=>$uid];
        $membershipState = [];
        foreach ($members as $existing) {
            if (!is_object($existing) || !method_exists($existing,'getUserType') || !method_exists($existing,'getLevel')) { continue; }
            $membershipState[] = [
                'id'=>method_exists($existing,'getId')?(string)$existing->getId():'',
                'userType'=>(int)$existing->getUserType(),
                'userId'=>method_exists($existing,'getUserId')?(string)$existing->getUserId():'',
                'singleId'=>method_exists($existing,'getSingleId')?(string)$existing->getSingleId():'',
                'level'=>(int)$existing->getLevel(),
                'status'=>method_exists($existing,'getStatus')?(string)$existing->getStatus():'',
            ];
        }
        usort($membershipState, static fn (array $a, array $b): int => strcmp(json_encode($a) ?: '', json_encode($b) ?: ''));
        $shown = ['action'=>$action,'team'=>$team,'member'=>['uid'=>$uid,'state'=>$memberState], 'actorLevel'=>$actorLevel,
            'circleConfig'=>method_exists($circle,'getConfig')?(int)$circle->getConfig():null,
            'membershipDigest'=>hash('sha256',json_encode($membershipState,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
        $impact = $action === 'add'
            ? Translator::t('Adding a member may grant them access to content shared with this team.')
            : Translator::t('Removing a member may revoke their access to content shared with this team.');
        $plan = ['action'=>$action,'team'=>$team,'member'=>$member,
            'current'=>$memberState === null ? null : ['teamRole'=>$this->role($memberState['level']),'status'=>$memberState['status']],
            'proposed'=>$action === 'add' ? ['teamRole'=>'member'] : ['membership'=>'removed'],
            'impact'=>$impact,'requiresConfirmation'=>true,PlanState::ARGUMENT=>$this->planState->of($tool,$actorId,$shown)];
        if ($action === 'remove') { $plan['_nativeMemberId'] = $memberState['id']; }
        return $plan;
    }

    private function requireVisibleAccount(string $uid): void {
        if (!$this->search instanceof ISearch) { throw new ToolFailure(ToolFailure::NOT_FOUND); }
        try {
            [$result] = $this->search->search($uid, [IShare::TYPE_USER], false, 10, 0);
        } catch (Throwable $e) {
            $this->logger->debug('MCP actor-visible account lookup failed', ['app'=>'mcp','exception_class'=>$e::class]);
            throw new ToolFailure(ToolFailure::NOT_FOUND);
        }
        foreach (($result['exact']['users'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['value']['shareWith'] ?? null) === $uid) { return; }
        }
        throw new ToolFailure(ToolFailure::NOT_FOUND);
    }

    private function role(int $level): string { return match($level) { 1=>'member',4=>'moderator',8=>'admin',9=>'owner',default=>'unknown' }; }

    private function argumentCircleId(array $arguments): string {
        $id = $arguments['team_id'] ?? null;
        if (!is_string($id)) { throw new InvalidArgumentException('Invalid team reference'); }
        $this->validateCircleId($id);
        return $id;
    }

    private function validateCircleId(string $circleId): void {
        if ($circleId === '' || strlen($circleId) > 255 || preg_match('/[\x00-\x1f\x7f]/', $circleId) === 1) { throw new InvalidArgumentException('Invalid team reference'); }
    }

    private function argumentUserId(array $arguments): string {
        $uid = $arguments['user_id'] ?? null;
        if (!is_string($uid) || trim($uid) === '' || strlen($uid) > 255 || preg_match('/[\x00-\x1f\x7f]/', $uid) === 1) { throw new InvalidArgumentException('Invalid user ID'); }
        return $uid;
    }

    private function visibleProbe(): object {
        $probe = ($this->probe)();
        if (!is_object($probe) || !method_exists($probe,'filterHiddenCircles') || !method_exists($probe,'filterSingleCircles') || !method_exists($probe,'mustBeMember')) {
            throw new \RuntimeException('Circles visibility filters unavailable');
        }
        $probe->filterHiddenCircles();
        $probe->filterSingleCircles();
        $probe->mustBeMember();
        return $probe;
    }

    /** @return array<string,mixed>|null */
    private function member(mixed $member): ?array {
        foreach (['getLevel','getUserType','getUserId','getDisplayName','isLocal'] as $method) {
            if (!is_object($member) || !method_exists($member,$method)) { throw new \RuntimeException('Invalid native member'); }
        }
        $level = (int)$member->getLevel();
        if ($level < 1) { return null; }
        $userType = (int)$member->getUserType();
        $role = match ($level) { 1=>'member', 4=>'moderator', 8=>'admin', 9=>'owner', default=>'unknown' };
        $displayName = mb_substr(trim((string)$member->getDisplayName()),0,255);
        if ($displayName === '') { $displayName = 'Unknown member'; }
        $local = (bool)$member->isLocal();
        $row = match ($userType) {
            self::TYPE_USER => $this->localUser((string)$member->getUserId(), $displayName, $role, $local),
            self::TYPE_GROUP => ['type'=>'group','displayName'=>$displayName,'role'=>$role,'membership'=>'direct'],
            self::TYPE_CIRCLE => ['type'=>'circle','displayName'=>$displayName,'role'=>$role,'membership'=>'direct'],
            self::TYPE_MAIL, self::TYPE_CONTACT => ['type'=>'external_contact','displayName'=>$displayName,'role'=>$role,'membership'=>'direct'],
            self::TYPE_APP => ['type'=>'app','displayName'=>$displayName,'role'=>$role,'membership'=>'direct'],
            default => throw new \RuntimeException('Unsupported native member type'),
        };
        return $row;
    }

    /** @return array<string,mixed> */
    private function localUser(string $uid, string $displayName, string $role, bool $local): ?array {
        if (!$local) { return ['type'=>'remote_user','displayName'=>$displayName,'role'=>$role,'membership'=>'direct']; }
        if ($uid === '') { throw new \RuntimeException('Local member identity missing'); }
        $user = $this->users->get($uid);
        if (!$user instanceof IUser || !$user->isEnabled()) { return null; }
        return ['type'=>'user','uid'=>$uid,'displayName'=>$displayName,'role'=>$role,'membership'=>'direct'];
    }

    private function withActor(string $actorId, \Closure $operation, bool $syncMembership = false): mixed {
        $actor = $this->session->getUser();
        if (!$actor instanceof IUser || $actor->getUID() !== $actorId) { throw new InvalidArgumentException('Team reader actor does not match the active session'); }
        if (!$this->apps->isEnabledForUser('circles',$actor)) { throw new ToolFailure(Translator::t('The team directory is unavailable.')); }
        try {
            // Nextcloud 33+ may defer loading enabled apps until a request explicitly needs their services.
            $this->apps->loadApp('circles');
            $manager = ($this->manager)();
            if (!is_object($manager) || !method_exists($manager,'getLocalFederatedUser') || !method_exists($manager,'startSession') || !method_exists($manager,'stopSession')) {
                throw new \RuntimeException('Circles session API unavailable');
            }
            try {
                $nativeActor = $manager->getLocalFederatedUser($actorId);
                // Native Circles can defer membership propagation unless the write opts into synchronous processing.
                $manager->startSession($nativeActor, $syncMembership);
                return $operation($manager);
            } finally {
                $manager->stopSession();
            }
        } catch (Throwable $e) {
            $this->logger->debug('MCP team directory read failed', ['app'=>'mcp','exception_class'=>$e::class,
                'exception_file'=>basename($e->getFile()),'exception_line'=>$e->getLine()]);
            if ($e instanceof ToolFailure || $e instanceof InvalidArgumentException || $e instanceof PlanChanged) { throw $e; }
            throw new ToolFailure(Translator::t('The team directory is unavailable; check the team and try again.'), 0, $e);
        }
    }
}
