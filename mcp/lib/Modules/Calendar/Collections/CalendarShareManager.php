<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Collections;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Platform\Dav\CalDav\CalendarCollectionWriter;
use OCA\Mcp\Platform\Dav\CalDav\CollectionStore;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;
use OCP\IUserManager;

/** Direct local-user calendar shares, restricted to calendars the actor owns. */
final class CalendarShareManager
{
    private const USER_PREFIX = 'principals/users/';
    public function __construct(
        private ScopedCollectionAccess $access,
        private CollectionStore $store,
        private IUserManager $users,
        private ISearch $search,
        private CalendarCollectionWriter $dav,
        private PlanState $states,
    ) {}

    private function owned(string $path, string $actor): object
    {
        $calendar = $this->access->resolve($actor, $path);
        if ($calendar->ownerId !== $actor) throw new ToolFailure('Only an owned calendar can be shared.');
        return $calendar;
    }

    /** @return list<array{userId:string,readOnly:bool}> */
    private function shares(int $calendarId): array
    {
        $out = [];
        foreach ($this->store->sharesOf($calendarId) as $share) {
            $principal = (string)($share['principal'] ?? '');
            if (str_starts_with($principal, 'principal:')) $principal = substr($principal, strlen('principal:'));
            if (!str_starts_with($principal, self::USER_PREFIX)) continue;
            $uid = substr($principal, strlen(self::USER_PREFIX));
            if ($uid === '') continue;
            $out[] = ['userId' => $uid, 'readOnly' => (bool)($share['readOnly'] ?? true)];
        }
        return $out;
    }

    public function list(array $arguments, string $actor): array
    {
        $calendar = $this->owned((string)($arguments['calendar'] ?? ''), $actor);
        return ToolResult::json(['calendar' => $calendar->path, 'shares' => $this->shares($calendar->id)]);
    }

    private function recipientVisible(string $uid): bool
    {
        [$result] = $this->search->search($uid, [IShare::TYPE_USER], false, 10, 0);
        foreach ($result['exact']['users'] ?? [] as $candidate) {
            if (($candidate['value']['shareWith'] ?? null) === $uid) return true;
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function prepare(array $arguments, string $actor, string $tool): array
    {
        $calendar = $this->owned((string)($arguments['calendar'] ?? ''), $actor);
        $uid = trim((string)($arguments['shareWith'] ?? ''));
        if ($uid === '' || $uid === $actor || preg_match('/[\x00-\x1f\x7f]/', $uid) || str_contains($uid, '/') || str_contains($uid, '\\')) {
            throw new \InvalidArgumentException('Choose another valid local Nextcloud user.');
        }
        $access = $arguments['access'] ?? null;
        if (!in_array($access, ['read', 'write', 'remove'], true)) throw new \InvalidArgumentException('Choose read, write, or remove access.');
        $matches = array_values(array_filter($this->shares($calendar->id), static fn(array $share): bool => $share['userId'] === $uid));
        if (count($matches) > 1) throw new ToolFailure('The calendar has an ambiguous direct share for this user.');
        $existing = $matches[0] ?? null;
        $remove = $access === 'remove';
        if ($remove && $existing === null) throw new \InvalidArgumentException('The calendar is not shared with this user.');
        if (!$remove) {
            if (!$this->recipientVisible($uid)) throw new \InvalidArgumentException('The recipient is not an available Nextcloud user.');
            $sharee = $this->users->get($uid);
            if ($sharee === null || !$sharee->isEnabled() || $sharee->getUID() !== $uid) throw new \InvalidArgumentException('The recipient is not an available Nextcloud user.');
            $readOnly = $access === 'read';
            if ($existing !== null && $existing['readOnly'] === $readOnly) throw new \InvalidArgumentException('The calendar already has this share access.');
            $displayName = $sharee->getDisplayName() ?: $uid;
        } else {
            $readOnly = null;
            $sharee = $this->users->get($uid);
            $displayName = ($sharee?->getDisplayName() ?: null) ?: $uid;
        }
        $state = [
            'calendarId' => $calendar->id, 'calendar' => $calendar->path, 'calendarUri' => $calendar->uri, 'owner' => $calendar->ownerPrincipal,
            'shareWith' => $uid, 'access' => $access, 'existingReadOnly' => $existing['readOnly'] ?? null,
        ];
        return $state + [
            'displayName' => $displayName,
            'existingAccess' => $existing === null ? null : ($existing['readOnly'] ? 'read-only' : 'read-write'),
            'action' => $remove ? 'remove_calendar_share' : 'share_calendar',
            'willWrite' => !$remove, 'willDelete' => $remove,
            PlanState::ARGUMENT => $this->states->of($tool, $actor, $state),
        ];
    }

    public function preview(array $arguments, string $actor, string $tool): array
    {
        return $this->prepare($arguments, $actor, $tool);
    }

    public function share(array $arguments, string $actor, string $tool): array
    {
        PlanState::require($arguments);
        $plan = $this->prepare($arguments, $actor, $tool);
        if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
            throw new PlanChanged($this->preview($arguments, $actor, $tool) + ['warning' => 'The calendar share changed after its preview. Review the updated plan.']);
        }
        $this->dav->shareCalendar($actor, $plan['calendarUri'], $plan['shareWith'], $plan['displayName'], $plan['access'] === 'remove' ? null : $plan['access'] === 'read');
        $matches = array_values(array_filter($this->shares($plan['calendarId']), static fn(array $share): bool => $share['userId'] === $plan['shareWith']));
        if ($plan['access'] === 'remove') {
            if ($matches !== []) throw new ToolFailure('The share removal was submitted but remains in the calendar ACL.');
            return ToolResult::json(['calendar' => $plan['calendar'], 'userId' => $plan['shareWith'], 'status' => 'removed']);
        }
        $readOnly = $plan['access'] === 'read';
        if (count($matches) !== 1 || $matches[0]['readOnly'] !== $readOnly) throw new ToolFailure('The share was submitted but could not be verified in the calendar ACL.');
        return ToolResult::json(['calendar' => $plan['calendar'], 'share' => $matches[0], 'access' => $plan['access'], 'status' => $plan['existingAccess'] === null ? 'shared' : 'updated']);
    }
}
