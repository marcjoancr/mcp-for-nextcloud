<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tags;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/** Native public system-tag operations, mediated by the caller's Files visibility guard. */
final class NativeTags {
    public const OBJECT_TYPE = 'files';
    public const MAX_PATHS = 20;

    public function __construct(
        private IRootFolder $rootFolder,
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private IUserManager $userManager,
        private FilesAccessGuard $visibility,
        private PlanState $states,
        private SharedWriteGuard $sharedWrites,
        private BackupLocation $backups,
    ) {}

    /** @return array{tags:list<array{id:string,name:string}>,truncated:bool,path?:string,current?:list<array{id:string,name:string}>} */
    public function list(string $userId, ?string $path = null): array {
        $user = $this->user($userId);
        $all = $this->publicAssignableTags($user);
        $truncated = count($all) > 1000;
        $all = array_slice($all, 0, 1000);
        $result = ['tags' => array_map(self::project(...), $all), 'truncated' => $truncated];
        if ($path !== null) {
            $file = $this->resolveFile($userId, $path, false);
            $current = array_map(self::project(...), $this->currentTagsAsObjects($file, $user));
            usort($current, self::sort(...));
            $result['path'] = PathGuard::normalize($path);
            $result['current'] = $current;
        }
        return $result;
    }

    /** Read-only plan. */
    public function createPreview(string $name, string $userId): array {
        $name = self::validName($name); $user = $this->user($userId);
        $existing = $this->findAssignableByName($name, $user);
        if ($existing !== null) return ['name' => $existing->getName(), 'tag_id' => (string)$existing->getId(), 'reused' => true, PlanState::ARGUMENT => $this->states->of('files_create_tag', $userId, ['name' => $name, 'tag_id' => (string)$existing->getId()]), 'message' => TagsMessages::createReusePlan()];
        if (!$this->tagManager->canUserCreateTag($user)) throw TagsMessages::creationDenied();
        return ['name' => $name, 'reused' => false, PlanState::ARGUMENT => $this->states->of('files_create_tag', $userId, ['name' => $name, 'create' => true]), 'message' => TagsMessages::createPlan()];
    }

    /** Rechecks native creation policy at commit; duplicate races reuse only an authorized public tag. */
    public function create(string $name, string $userId, string $planState): array {
        $name = self::validName($name); $user = $this->user($userId);
        $plan = $this->createPreview($name, $userId);
        if (!PlanState::matches($plan[PlanState::ARGUMENT], [PlanState::ARGUMENT => $planState])) throw new PlanChanged($plan);
        $existing = $this->findAssignableByName($name, $user);
        if ($existing !== null) return ['tag' => self::project($existing), 'created' => false];
        if (!$this->tagManager->canUserCreateTag($user)) throw TagsMessages::creationDenied();
        try {
            $tag = $this->tagManager->createTag($name, true, true);
        } catch (\OCP\SystemTag\TagAlreadyExistsException) {
            $tag = $this->findAssignableByName($name, $user);
            if ($tag === null) throw TagsMessages::tagUnavailable();
            return ['tag' => self::project($tag), 'created' => false];
        }
        if (!$this->allowedTag($tag, $user)) throw TagsMessages::tagUnavailable();
        return ['tag' => self::project($tag), 'created' => true];
    }

    /** @param list<string> $paths @param list<string> $add @param list<string> $remove */
    public function setPreview(array $paths, array $add, array $remove, string $userId): array {
        $paths = self::paths($paths); $add = self::names($add); $remove = self::names($remove);
        if ($add === [] && $remove === []) throw TagsMessages::emptyChange();
        $addKeys = array_map(static fn (string $name): string => mb_strtolower($name, 'UTF-8'), $add);
        $removeKeys = array_map(static fn (string $name): string => mb_strtolower($name, 'UTF-8'), $remove);
        if (array_intersect($addKeys, $removeKeys) !== []) throw TagsMessages::conflictingChange();
        $user = $this->user($userId); $changes = [];
        foreach ($paths as $path) {
            $file = $this->resolveFile($userId, $path, true);
            $currentTags = $this->currentTagsAsObjects($file, $user);
            $current = array_map(static fn (ISystemTag $tag): string => $tag->getName(), $currentTags);
            $addIds = []; $removeIds = [];
            foreach (array_merge($add, $remove) as $name) {
                $tag = $this->findAssignableByName($name, $user);
                if ($tag === null) throw TagsMessages::tagUnavailable();
                if (in_array($name, $remove, true) && in_array((string)$tag->getId(), $this->visibility->getHiddenTagIds(), true)) throw TagsMessages::protectedTag();
                if (in_array($name, $add, true) && !in_array($name, $current, true) && !$this->tagManager->canUserAssignTag($tag, $user)) throw TagsMessages::assignmentDenied();
                if (in_array($name, $add, true)) $addIds[] = (string)$tag->getId();
                if (in_array($name, $remove, true)) $removeIds[] = (string)$tag->getId();
            }
            sort($current, SORT_STRING);
            $shared = $this->sharedWrites->guard($file, $userId, PathGuard::normalize($path), false);
            $changes[] = [
                'path' => PathGuard::normalize($path), 'file_id' => (string)$file->getId(),
                'current' => $current, 'current_ids' => array_map(static fn (ISystemTag $tag): string => (string)$tag->getId(), $currentTags),
                'add' => $add, 'add_ids' => $addIds, 'remove' => $remove, 'remove_ids' => $removeIds, 'shared' => $shared,
            ];
        }
        $state = $this->states->of('files_set_tags', $userId, ['changes' => $changes]);
        return ['changes' => $changes, PlanState::ARGUMENT => $state, 'message' => TagsMessages::editPlan()];
    }

    /** @param list<string> $paths @param list<string> $add @param list<string> $remove */
    public function set(array $paths, array $add, array $remove, string $userId, string $planState, bool $confirmShared): array {
        $plan = $this->setPreview($paths, $add, $remove, $userId);
        if (!PlanState::matches($plan[PlanState::ARGUMENT], [PlanState::ARGUMENT => $planState])) throw new PlanChanged($plan);
        $user = $this->user($userId); $updated = []; $pending = [];
        foreach ($plan['changes'] as $change) {
            $file = $this->resolveFile($userId, $change['path'], true); $assign = []; $unassign = [];
            if (($confirmation = $this->sharedWrites->guard($file, $userId, $change['path'], $confirmShared)) !== null) return $confirmation;
            foreach ($change['add'] as $name) {
                $tag = $this->findAssignableByName($name, $user);
                if ($tag === null || !$this->tagManager->canUserAssignTag($tag, $user)) throw TagsMessages::assignmentDenied();
                $assign[] = (string)$tag->getId();
            }
            foreach ($change['remove'] as $name) {
                $tag = $this->findAssignableByName($name, $user);
                if ($tag === null || !$this->tagManager->canUserAssignTag($tag, $user) || in_array((string)$tag->getId(), $this->visibility->getHiddenTagIds(), true)) throw TagsMessages::protectedTag();
                $unassign[] = (string)$tag->getId();
            }
            $pending[] = ['file' => $file, 'assign' => $assign, 'unassign' => $unassign, 'path' => $change['path']];
        }
        // Preflight every target before touching any file, so a later inaccessible or unconfirmed item cannot leave a partial batch.
        foreach ($pending as $item) {
            if ($item['assign'] !== []) $this->tagMapper->assignTags((string)$item['file']->getId(), self::OBJECT_TYPE, $item['assign']);
            if ($item['unassign'] !== []) $this->tagMapper->unassignTags((string)$item['file']->getId(), self::OBJECT_TYPE, $item['unassign']);
            $updated[] = $item['path'];
        }
        return ['updated' => $updated, 'added' => $add, 'removed' => $remove];
    }

    private function resolveFile(string $userId, string $path, bool $write): File {
        $path = PathGuard::normalize($path);
        if ($write && $this->backups->contains($path)) throw new ToolFailure(EditingMessages::backupPath());
        $root = $this->rootFolder->getUserFolder($userId);
        $file = NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibility));
        if ($write && !$file->isUpdateable()) throw TagsMessages::fileNotWritable();
        return $file;
    }

    /** @return list<ISystemTag> */
    private function currentTagsAsObjects(File $file, IUser $user): array {
        $key = (string)$file->getId();
        $ids = $this->tagMapper->getTagIdsForObjects([$key], self::OBJECT_TYPE)[$key] ?? [];
        return array_values(array_filter($this->tagManager->getTagsByIds($ids, $user), fn (ISystemTag $tag): bool => $this->allowedTag($tag, $user)));
    }

    /** @return list<ISystemTag> */
    private function publicAssignableTags(IUser $user): array {
        $tags = array_values(array_filter($this->tagManager->getAllTags(null, false), fn (ISystemTag $tag): bool => $this->allowedTag($tag, $user)));
        usort($tags, static fn (ISystemTag $a, ISystemTag $b): int => strcasecmp($a->getName(), $b->getName()));
        return $tags;
    }

    private function findAssignableByName(string $name, IUser $user): ?ISystemTag {
        $needle = mb_strtolower($name, 'UTF-8');
        foreach ($this->tagManager->getAllTags(null, $name) as $tag) if (mb_strtolower($tag->getName(), 'UTF-8') === $needle && $this->allowedTag($tag, $user)) return $tag;
        return null;
    }

    private function allowedTag(ISystemTag $tag, IUser $user): bool {
        return !in_array((string)$tag->getId(), $this->visibility->getHiddenTagIds(), true)
            && $tag->isUserVisible() && $tag->isUserAssignable()
            && $this->tagManager->canUserSeeTag($tag, $user) && $this->tagManager->canUserAssignTag($tag, $user);
    }

    private function user(string $userId): IUser { return $this->userManager->get($userId) ?? throw TagsMessages::userUnavailable(); }
    private static function validName(string $name): string {
        $name = trim($name);
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || strlen($name) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $name) !== 0) throw TagsMessages::invalidName();
        return $name;
    }
    /** @param list<string> $paths @return list<string> */
    private static function paths(array $paths): array {
        if ($paths === [] || count($paths) > self::MAX_PATHS) throw TagsMessages::invalidPaths();
        $out = []; foreach ($paths as $path) { if (!is_string($path) || $path === '') throw TagsMessages::invalidPaths(); $out[] = PathGuard::normalize($path); }
        if (count(array_unique($out)) !== count($out)) throw TagsMessages::invalidPaths(); return $out;
    }
    /** @param list<string> $names @return list<string> */
    private static function names(array $names): array {
        if (count($names) > 100) throw TagsMessages::invalidNames();
        $out = []; foreach ($names as $name) { if (!is_string($name)) throw TagsMessages::invalidNames(); $out[] = self::validName($name); }
        if (count(array_unique(array_map(static fn (string $name): string => mb_strtolower($name, 'UTF-8'), $out))) !== count($out)) throw TagsMessages::invalidNames(); return $out;
    }
    /** @return array{id:string,name:string} */
    private static function project(ISystemTag $tag): array { return ['id' => (string)$tag->getId(), 'name' => $tag->getName()]; }
    private static function sort(array $a, array $b): int { return strcasecmp($a['name'], $b['name']); }
}
