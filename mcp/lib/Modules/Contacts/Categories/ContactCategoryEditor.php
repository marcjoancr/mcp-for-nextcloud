<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Categories;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use Throwable;

/** Confirmed, per-card CATEGORIES edits in one writable address book. */
final class ContactCategoryEditor {
    private const TOOL = 'contacts_update_categories';
    private const MAX_CONTACTS = 100;

    public function __construct(
        private ContactAccess $access,
        private ContactRepository $repository,
        private ContactCard $cards,
        private ContactDav $dav,
        private PlanState $states,
        private SharedCollectionNotice $shared,
    ) {}

    /** @param array<string,mixed> $arguments */
    public function preview(array $arguments, string $userId): array {
        return $this->prepare($arguments, $userId)['plan'];
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, string $userId): array {
        PlanState::require($arguments);
        $prepared = $this->prepare($arguments, $userId);
        if (!PlanState::matches($prepared['plan']['plan_state'], $arguments)) {
            throw new PlanChanged($prepared['plan']);
        }
        if ($prepared['plan']['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
            throw new ToolFailure('Confirm the change to the shared collection with confirm_shared=true.');
        }

        $outcomes = [];
        foreach ($prepared['entries'] as $entry) {
            if ($entry['status'] === 'stale') {
                $outcomes[] = $entry + ['result' => 'stale_reference'];
                continue;
            }
            if ($entry['status'] === 'unchanged') {
                $outcomes[] = $entry + ['result' => 'unchanged'];
                continue;
            }
            try {
                $book = $this->access->resolve($userId, $prepared['plan']['addressbook']['path'], true);
                if ($book->id !== $prepared['book']->id || $book->ownerPrincipal !== $prepared['book']->ownerPrincipal) {
                    $outcomes[] = $entry + ['result' => 'destination_changed'];
                    continue;
                }
                $latest = $this->repository->row($book->id, $entry['uri']);
                if ($latest['etag'] !== $entry['etag']) {
                    $outcomes[] = $entry + ['result' => 'changed_before_write'];
                    continue;
                }
                try {
                    $this->dav->update($userId, $book->uri, $entry['uri'], $entry['etag'], $entry['card']->serialize());
                } catch (Throwable) {
                    // A DAV failure can happen after a server committed. Read back before reporting an outcome.
                }
                $written = $this->repository->row($book->id, $entry['uri']);
                $saved = $this->cards->parse($written['data']);
                $actual = $this->categories($saved);
                $outcomes[] = $entry + [
                    'result' => $actual === $entry['after']['categories'] ? 'updated' : 'unverified',
                    'etag' => $written['etag'],
                ];
            } catch (Throwable) {
                $outcomes[] = $entry + ['result' => 'failed'];
            }
        }
        return ToolResult::json([
            'addressbook' => $prepared['book']->path,
            'contacts' => array_map(static function (array $entry): array {
                unset($entry['card']);
                return $entry;
            }, $outcomes),
        ]);
    }

    /** @param array<string,mixed> $arguments */
    private function prepare(array $arguments, string $userId): array {
        if ($this->access->isSystemPath($userId, $arguments['addressbook'])) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
        $book = $this->access->resolve($userId, $arguments['addressbook'], true);
        $refs = $arguments['contacts'] ?? null;
        $action = $arguments['action'] ?? null;
        $category = $arguments['category'] ?? null;
        $replacement = $arguments['replacement'] ?? null;
        if (!is_array($refs) || $refs === [] || count($refs) > self::MAX_CONTACTS
            || !in_array($action, ['add', 'remove', 'rename'], true)
            || !is_string($category) || trim($category) === '' || mb_strlen($category) > 255
            || ($action === 'rename' && (!is_string($replacement) || trim($replacement) === '' || mb_strlen($replacement) > 255))) {
            throw new InvalidArgumentException('Invalid category operation.');
        }
        if ($action === 'rename' && $category === $replacement) {
            throw new InvalidArgumentException('The source and replacement categories must differ.');
        }
        $seen = [];
        $entries = [];
        $snapshot = [];
        foreach ($refs as $ref) {
            if (!is_array($ref) || !is_string($ref['uri'] ?? null) || !is_string($ref['etag'] ?? null) || $ref['etag'] === '') {
                throw new InvalidArgumentException('Each selected contact requires its URI and ETag.');
            }
            $uri = $ref['uri'];
            if (isset($seen[$uri])) { throw new InvalidArgumentException('A contact can only appear once.'); }
            $seen[$uri] = true;
            $row = $this->repository->row($book->id, $uri);
            $card = $this->cards->parse($row['data']);
            $before = $this->categories($card);
            $after = $this->apply($before, $action, $category, $replacement);
            $status = $ref['etag'] !== $row['etag'] ? 'stale' : ($before === $after ? 'unchanged' : 'update');
            $copy = $this->cards->patch($card, ['categories' => $after]);
            $entries[] = [
                'uri' => $uri,
                'etag' => $row['etag'],
                'status' => $status,
                'name' => (string)($card->FN ?? ''),
                'before' => ['categories' => $before],
                'after' => ['categories' => $after],
                'card' => $copy,
            ];
            $snapshot[] = [
                'uri' => $uri,
                'referenceEtag' => $ref['etag'],
                'etag' => $row['etag'],
                'sha256' => hash('sha256', $row['data']),
                'before' => $before,
                'after' => $after,
                'status' => $status,
            ];
        }
        $notice = $this->shared->forCollection($book, $userId);
        $plan = [
            'action' => self::TOOL,
            'addressbook' => ['id' => $book->id, 'path' => $book->path, 'name' => $book->name, 'owner' => $book->ownerPrincipal],
            'operation' => ['action' => $action, 'category' => $category, 'replacement' => $replacement],
            'contacts' => array_map(static function (array $entry): array {
                unset($entry['card']);
                return $entry;
            }, $entries),
            'shared' => $notice === null ? [] : [$notice],
        ];
        $plan['plan_state'] = $this->states->of(self::TOOL, $userId, [
            'book' => ['id' => $book->id, 'uri' => $book->uri, 'owner' => $book->ownerPrincipal],
            'action' => $action,
            'category' => $category,
            'replacement' => $replacement,
            'contacts' => $snapshot,
        ]);
        return ['book' => $book, 'plan' => $plan, 'entries' => $entries];
    }

    /** @param list<string> $categories @return list<string> */
    private function apply(array $categories, string $action, string $category, ?string $replacement): array {
        if ($action === 'add') {
            if (!in_array($category, $categories, true)) { $categories[] = $category; }
        } elseif ($action === 'remove') {
            $categories = array_values(array_filter($categories, static fn (string $value): bool => $value !== $category));
        } else {
            $found = in_array($category, $categories, true);
            if ($found) {
                $categories = array_values(array_filter($categories, static fn (string $value): bool => $value !== $category));
                if (!in_array($replacement, $categories, true)) { $categories[] = $replacement; }
            }
        }
        return $categories;
    }

    /** @return list<string> */
    private function categories(\Sabre\VObject\Component\VCard $card): array {
        return array_values(array_map('strval', $this->cards->item($card)['categories'] ?? []));
    }
}
