<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Circles\CirclesManager;
use OCA\Deck\Db\Acl;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCA\Deck\Sharing\DeckShareProvider;
use OCA\Mcp\Tests\Integration\Guests\GuestDeckParityProbe;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use RuntimeException;
use Throwable;

/**
 * Disposable native file, ACL and share controls, always in the fresh actor process.
 */
final class CardFileLinkFixture {
    /**
     * Bound fixed input for fixture bytes, distinct from any credential.
     */
    private const BYTES_PREFIX = 'Native file link proof ';

    /**
     * Bytes of entropy for collision-free disposable fixture names.
     */
    private const SUFFIX_RANDOM_BYTES = 6;

    /**
     * Native mtime advance that makes an etag change observable even within the same wall-clock second.
     */
    private const ETAG_MTIME_STEP_SECONDS = 2;

    /**
     * Dispatch one explicit native fixture action without applying MCP policy or Guests lifecycle manually.
     *
     * @param string $uid Disposable Deck actor already bound by the actor bootstrap.
     * @param string $action Exact fixture action.
     * @param array<string, mixed> $input Owned resource ledger and operation input.
     * @return array<string, mixed> Native state observations.
     */
    public static function run(string $uid, string $action, array $input): array {
        GuestDeckParityProbe::registerBackend(Server::get(IAppManager::class));
        return match ($action) {
            'link-seed' => self::seed($uid, $input),
            'link-inspect' => self::inspect($uid, $input),
            'link-native' => self::native($uid, $input),
            'link-cleanup' => self::cleanup($uid, $input),
            'link-change' => self::change($uid, $input),
            'link-session' => self::session($uid, $input),
            'link-admission' => self::outcome($uid, $input + ['admission'=>true]),
            'link-outcome' => self::outcome($uid, $input),
            'link-version' => self::version($uid, $input),
            default => throw new RuntimeException('Unknown file link fixture action'),
        };
    }

    /**
     * Create a private original, an independent share and the requested board audience.
     *
     * @param string $uid Disposable owner.
     * @param array<string, mixed> $input Audience kind and optional guest actor.
     * @return array<string, mixed> Complete owned resource ledger and original identity/hash.
     */
    private static function seed(string $uid, array $input): array {
        $suffix = bin2hex(random_bytes(self::SUFFIX_RANDOM_BYTES));
        $boards = Server::get(BoardService::class);
        $board = $boards->create('Link proof ' . $suffix, $uid, '008800');
        $stack = Server::get(StackService::class)->create('Files', $board->getId(), 0);
        $card = Server::get(CardService::class)->create('Link ' . $suffix, $stack->getId(), 'plain', 0, $uid);
        $reader = $input['guest'] ?? DeckFixture::READER;
        if (isset($input['guest'])) {
            $boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_USER, DeckFixture::READER, false, false, false);
        }
        $boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_USER, $reader, true, false, false);
        $folder = Server::get(IRootFolder::class)->getUserFolder($uid);
        $file = $folder->newFile('deck-link-' . $suffix . '.txt', self::BYTES_PREFIX . $suffix);
        $manager = Server::get(IManager::class);
        $independent = $manager->createShare($manager->newShare()->setNode($file)->setShareType(IShare::TYPE_USER)
            ->setSharedWith(DeckFixture::OUTSIDER)->setSharedBy($uid)->setPermissions(Constants::PERMISSION_READ));
        $editorAcl = $boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_USER, DeckFixture::EDITOR, true, false, false);
        $editorShare = $manager->createShare($manager->newShare()->setNode($file)->setShareType(IShare::TYPE_USER)
            ->setSharedWith(DeckFixture::EDITOR)->setSharedBy($uid)
            ->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE));
        $guestShare = null;
        if (isset($input['guest'])) {
            $guestShare = $manager->createShare($manager->newShare()->setNode($file)->setShareType(IShare::TYPE_USER)
                ->setSharedWith($input['guest'])->setSharedBy($uid)->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE))->getFullId();
        }
        $circleId = null;
        $otherBoardId = null;
        if (($input['kind'] ?? 'user') === 'group') {
            $group = Server::get(\OCP\IGroupManager::class)->createGroup(DeckFixture::GROUP);
            $group->addUser(Server::get(\OCP\IUserManager::class)->get(DeckFixture::READER));
            $boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_GROUP, DeckFixture::GROUP, false, false, false);
        } elseif (in_array($input['kind'] ?? 'user', ['circle','other-circle'], true)) {
            $circles = Server::get(CirclesManager::class);
            $circles->startSession($circles->getLocalFederatedUser($uid), true);
            $circle = $circles->createCircle('Link proof ' . $suffix, null, false, false, false);
            $circles->addMember($circle->getSingleId(), $circles->getLocalFederatedUser(DeckFixture::READER));
            $circleId = $circle->getSingleId();
            $circleBoard = $board;
            if (($input['kind'] ?? '') === 'other-circle') {
                $circleBoard = $boards->create('Unrelated Circle board ' . $suffix, $uid, '008800');
                $otherBoardId = (int)$circleBoard->getId();
                if (isset($input['guest'])) { $boards->addAcl($otherBoardId, Acl::PERMISSION_TYPE_USER, $input['guest'], false, false, false); }
            }
            $boards->addAcl($circleBoard->getId(), Acl::PERMISSION_TYPE_CIRCLE, $circleId, false, false, false);
        }
        return ['boardId' => (int)$board->getId(), 'cardId' => (int)$card->getId(), 'fileId' => (int)$file->getId(),
            'fileName' => $file->getName(), 'etag' => $file->getEtag(), 'hash' => hash('sha256', $file->getContent()),
            'otherBoardId'=>$otherBoardId, 'independent' => $independent->getFullId(), 'independentId' => $independent->getId(), 'circleId' => $circleId,
            'groupId' => ($input['kind'] ?? 'user') === 'group' ? DeckFixture::GROUP : null, 'reader' => $reader, 'owner' => $uid, 'guestShare' => $guestShare,
            'editorAcl' => $editorAcl->getId(), 'editorShare' => $editorShare->getFullId()];
    }

    /**
     * Observe exact original file bytes and association identities without modifying a file or share.
     *
     * @param string $uid Disposable actor whose own Files tree is observed.
     * @param array<string, mixed> $input Owned canonical file/card identities.
     * @return array<string, mixed> Current readability, exact file hash and visible native association state.
     */
    private static function inspect(string $uid, array $input): array {
        $node = Server::get(IRootFolder::class)->getUserFolder($uid)->getFirstNodeById($input['fileId']);
        if ($node === null || !$node->isReadable()) {
            return ['readable' => false, 'fileId' => null, 'hash' => null, 'links' => []];
        }
        $links = [];
        foreach (Server::get(DeckShareProvider::class)->getSharesByPath($node) as $share) {
            if ((string)$share->getSharedWith() === (string)$input['cardId']) {
                $links[] = ['id' => $share->getId(), 'fileId' => (int)$share->getNodeId(),
                    'permissions' => (int)$share->getPermissions(), 'type' => $share->getShareType()];
            }
        }
        $independent = Server::get(IManager::class)->getShareById($input['independent']);
        return ['readable' => true, 'fileId' => (int)$node->getId(), 'hash' => hash('sha256', $node->getContent()),
            'links' => $links, 'permissions' => (int)$node->getPermissions(),
            'independent' => ['id' => $independent->getFullId(), 'fileId' => (int)$independent->getNodeId(),
                'recipient' => $independent->getSharedWith(), 'permissions' => (int)$independent->getPermissions()]];
    }

    /**
     * Probe the native type-12 bypass separately from MCP, preserving the original bytes.
     *
     * @param string $uid Disposable owner.
     * @param array<string, mixed> $input Exact owned node/card identities.
     * @return array<string, mixed> Native creation status and exception class/code only.
     */
    private static function native(string $uid, array $input): array {
        $node = Server::get(IRootFolder::class)->getUserFolder($uid)->getFirstNodeById($input['fileId']);
        $manager = Server::get(IManager::class);
        try {
            $share = $manager->createShare($manager->newShare()->setNode($node)->setShareType(IShare::TYPE_DECK)
                ->setSharedWith((string)$input['cardId'])->setSharedBy($uid)->setPermissions(Constants::PERMISSION_READ));
            return ['accepted' => true, 'id' => $share->getFullId(), 'permissions' => $share->getPermissions()];
        } catch (Throwable $failure) {
            return ['accepted' => false, 'class' => $failure::class, 'code' => $failure->getCode()];
        }
    }

    /**
     * Remove only fixture-owned shares, board and original after all preservation assertions finished.
     *
     * @param string $uid Disposable owner.
     * @param array<string, mixed> $input Complete owned ledger.
     * @return array<string, mixed> Cleanup completion evidence.
     */
    private static function cleanup(string $uid, array $input): array {
        if ($uid !== $input['owner']) {
            throw new RuntimeException('Only the fixture owner cleans its resources');
        }
        $manager = Server::get(IManager::class);
        $node = Server::get(IRootFolder::class)->getUserFolder($uid)->getFirstNodeById($input['fileId']);
        foreach (Server::get(DeckShareProvider::class)->getSharesByPath($node) as $share) {
            if ((string)$share->getSharedWith() === (string)$input['cardId']) {
                $manager->deleteShare($share);
            }
        }
        $manager->deleteShare($manager->getShareById($input['independent']));
        $manager->deleteShare($manager->getShareById($input['editorShare']));
        if ($input['guestShare'] !== null) {
            $manager->deleteShare($manager->getShareById($input['guestShare']));
        }
        Server::get(BoardService::class)->delete($input['boardId']);
        if (($input['otherBoardId'] ?? null) !== null) { Server::get(BoardService::class)->delete($input['otherBoardId']); }
        if ($input['circleId'] !== null) {
            $circles = Server::get(CirclesManager::class);
            $circles->startSession($circles->getLocalFederatedUser($uid), true);
            $circles->destroyCircle($input['circleId']);
        }
        if (($input['groupId'] ?? null) !== null) { Server::get(\OCP\IGroupManager::class)->get($input['groupId'])->delete(); }
        $node->delete();
        return ['cleaned' => true];
    }

    /**
     * Change one native prerequisite after a real preview, in a new actor process.
     *
     * @param string $uid Disposable owner.
     * @param array<string, mixed> $input Owned ledger and exact changed prerequisite.
     * @return array<string, mixed> Observed mutation outcome.
     */
    private static function change(string $uid, array $input): array {
        $boards = Server::get(BoardService::class);
        $manager = Server::get(IManager::class);
        $node = Server::get(IRootFolder::class)->getUserFolder($uid)->getFirstNodeById($input['fileId']);
        switch ($input['change']) {
            case 'etag':
                $old = $node->getEtag();
                $node->putContent(self::BYTES_PREFIX . 'changed');
                $node->touch(time() + self::ETAG_MTIME_STEP_SECONDS);
                return ['before' => $old, 'after' => $node->getEtag()];
            case 'member':
                $boards->addAcl($input['boardId'], Acl::PERMISSION_TYPE_USER, DeckFixture::OUTSIDER, false, false, false);
                break;
            case 'edit':
                $boards->updateAcl($input['editorAcl'], false, false, false);
                break;
            case 'share':
                $manager->updateShare($manager->getShareById($input['editorShare'])->setPermissions(Constants::PERMISSION_READ));
                break;
            case 'archive':
                Server::get(CardService::class)->archive($input['cardId']);
                break;
            default:
                throw new RuntimeException('Unknown file link prerequisite change');
        }
        return ['changed' => true];
    }

    /**
     * Observe the current native file version in a separate process, avoiding an earlier Node object's cache.
     *
     * @param string $uid Disposable owner.
     * @param array<string, mixed> $input Owned file identity.
     * @return array{etag:string,hash:string} Fresh native version and bytes.
     */
    private static function version(string $uid, array $input): array {
        $node = Server::get(IRootFolder::class)->getUserFolder($uid)->getFirstNodeById($input['fileId']);
        return ['etag' => $node->getEtag(), 'hash' => hash('sha256', $node->getContent())];
    }
    /** Real persistence with an injected lost response at the IManager boundary; no production fixture hooks. */
    private static function outcome(string $uid, array $input): array {
        $manager = Server::get(IManager::class);
        $admission = $input['admission'] ?? false;
        $observations = [];
        $observe = static function (string $stage) use (&$observations):void {
            $observations[] = ['stage'=>$stage, 'bypass'=>class_exists(CirclesManager::class, false)
                ? Server::get(\OCA\Circles\Service\FederatedUserService::class)->canBypassCurrentUserCondition() : false];
        };
        if ($admission) {
            Server::get(\OCA\Deck\Service\CirclesService::class)->isUserInCircle($input['circleId'], $uid);
            Server::get(BoardService::class)->findAll(-2, false, true); // Control: native unrelated-board enrichment enters super state.
            $observe('native-enrichment-control');
        }
        $probe = new class('nativeLinkBoundary') extends \PHPUnit\Framework\TestCase {
            public function failAfterNativeCreate(IManager $real, bool $admission, bool $removeAfterCreate, \Closure $observe): IManager {
                $manager = $this->createMock(IManager::class);
                foreach (get_class_methods(IManager::class) as $method) {
                    if (!in_array($method, ['newShare', 'getSharesBy', 'createShare', 'shareApiEnabled', 'sharingDisabledForUser', 'shareWithGroupMembersOnly', 'allowGroupSharing', 'shareApiInternalDefaultExpireDate', 'shareApiInternalDefaultExpireDateEnforced'], true)) {
                        $manager->expects(self::never())->method($method);
                    }
                }
                $manager->method('newShare')->willReturnCallback($real->newShare(...));
                $manager->method('getSharesBy')->willReturnCallback($real->getSharesBy(...));
                foreach (['shareApiEnabled','sharingDisabledForUser','shareWithGroupMembersOnly','allowGroupSharing','shareApiInternalDefaultExpireDate','shareApiInternalDefaultExpireDateEnforced'] as $method) {
                    $manager->method($method)->willReturnCallback(static function (...$arguments) use ($real, $method, $observe) { $observe('policy:' . $method); return $real->$method(...$arguments); });
                }
                $manager->method('createShare')->willReturnCallback(static function (IShare $share) use ($real, $admission, $removeAfterCreate, $observe): IShare {
                    $observe('native-create');
                    $created = $real->createShare($share);
                    if ($admission) { return $created; }
                    if ($removeAfterCreate) { $real->deleteShare($created); } // Inject external unlink of this exact newly created association.
                    throw new RuntimeException('Injected native response loss after actual persistence');
                });
                return $manager;
            }
        };
        $source = Server::get(\OCA\Mcp\Modules\Deck\Files\Linking\NativeLinkSource::class);
        $gateway = static function (string $actor, int $card, bool $edit) use ($source, $observe):array {
            $result = $source->context($actor, $card, $edit); $observe('source-before-files-or-recipients'); return $result;
        };
        $clear = static function ():void { if (class_exists(CirclesManager::class, false)) { Server::get(CirclesManager::class)->stopSession(); } };
        $logger = Server::get(\Psr\Log\LoggerInterface::class);
        $plans = Server::get(\OCA\Mcp\Mcp\Confirmation\PlanState::class);
        $checkedManager = $probe->failAfterNativeCreate($manager, $admission, $input['removeAfterCreate'] ?? false, $observe);
        $native = new \OCA\Mcp\Modules\Deck\Files\Linking\NativeCardFileLink($checkedManager,
            Server::get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class), static fn()=> Server::get(DeckShareProvider::class));
        $grants = Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
        $guard = Server::get(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class);
        $apps = Server::get(IAppManager::class);
        $users = Server::get(\OCP\IUserManager::class);
        $directory = new \OCA\Mcp\Modules\Deck\Recipients\DeckRecipientDirectory(
            static fn()=> Server::get(\OCA\Deck\Db\AclMapper::class), static fn()=> Server::get(\OCA\Deck\Service\CirclesService::class),
            $users, Server::get(\OCP\IGroupManager::class), $apps, static fn()=> Server::get(CirclesManager::class)->stopSession());
        $recipients = new \OCA\Mcp\Modules\Deck\Recipients\BoardRecipientResolver($gateway, $grants, $directory, $plans, $logger, $clear);
        $policy = new \OCA\Mcp\Modules\Deck\Files\Linking\CardLinkPolicy($checkedManager, Server::get(\OCP\IConfig::class), $apps, $users,
            Server::get(\OCP\IUserSession::class), static function () use ($observe) { $observe('guest-identity'); return Server::get(\OCA\Guests\GuestManager::class); });
        $prepare = new \OCA\Mcp\Modules\Deck\Files\Linking\CardFileLinkPreparation($gateway, $grants, $guard,
            new \OCA\Mcp\Modules\Deck\Files\CardFileNodeAccess($guard, Server::get(IRootFolder::class)), $policy, $native,
            Server::get(\OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class), $recipients, $clear);
        $operation = new \OCA\Mcp\Modules\Deck\Files\Linking\CardFileLinking($logger, static fn():array=>[
            'prepare'=>$prepare, 'native'=>$native, 'plans'=>new \OCA\Mcp\Modules\Deck\Files\Linking\CardLinkPlan($plans),
            'shared'=>Server::get(\OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard::class)], static function ():void {
                if (class_exists(CirclesManager::class, false)) { Server::get(CirclesManager::class)->stopSession(); }
            });
        $args=['cardId'=>$input['cardId'], 'fileId'=>$input['probeFileId'] ?? $input['fileId']];
        if ($admission) {
            $result = $operation->handle($args, $uid);
            if (!($result['isError'] ?? false)) {
                $plan = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
                $result = $operation->handle($args + ['confirm'=>true, 'confirm_shared'=>true, 'plan_state'=>$plan['plan_state']], $uid);
            }
            return ['result'=>$result, 'observations'=>$observations];
        }
        $confirmed=$args+['confirm'=>true,'plan_state'=>$operation->preview($args,$uid)['plan_state']];
        $result=$operation->handle($confirmed,$uid);
        $retry=($input['removeAfterCreate'] ?? false) ? null : $operation->handle($confirmed,$uid);
        return ['result'=>$result, 'retry'=>$retry, 'native'=>self::inspect($uid,$input), 'observations'=>$observations];
    }

    /** Warm native membership then exercise the actual tool factory through preview and write/readback. */
    private static function session(string $uid, array $input): array {
        $circles = Server::get(\OCA\Deck\Service\CirclesService::class);
        if (!$circles->isUserInCircle($input['circleId'], $uid)) { throw new RuntimeException('Tool session probe requires circle membership'); }
        $tool = Server::get(\OCA\Mcp\Modules\Deck\Tools\LinkCardFile::class);
        $context = new \OCA\Mcp\Mcp\Tools\ToolContext($uid);
        $arguments = ['cardId'=>$input['cardId'], 'fileId'=>$input['fileId']];
        $plan = $tool->preview($arguments, $context);
        $native = Server::get(\OCA\Circles\Service\FederatedUserService::class);
        $state = ['previewBypass'=>$native->canBypassCurrentUserCondition(), 'previewHasCurrentUser'=>$native->hasCurrentUser()];
        $reply = $tool->execute($arguments + ['confirm'=>true, 'plan_state'=>$plan['plan_state']], $context);
        $state['executeBypass'] = $native->canBypassCurrentUserCondition();
        $state['executeHasCurrentUser'] = $native->hasCurrentUser();
        $state['result'] = $reply;
        return $state;
    }

}
