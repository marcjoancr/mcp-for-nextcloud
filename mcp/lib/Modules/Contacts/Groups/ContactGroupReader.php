<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Groups;

use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

final class ContactGroupReader
{
    public function __construct(
        private ContactAccess $access,
        private ContactStore $store,
        private ContactCard $cards,
    ) {
    }

    public function execute(array $arguments, string $userId): array
    {
        if (isset($arguments['addressbook'])) {
            if ($this->access->isSystemPath($userId, $arguments['addressbook'])) {
                throw new ToolFailure(FileAccessMessages::notFound());
            }
            $books = [$this->access->resolve($userId, $arguments['addressbook'])];
        } else {
            $books = $this->access->visible($userId);
        }
        $groups = [];
        foreach ($books as $book) {
            foreach ($this->store->cards($book->id) as $row) {
                try {
                    $contact = $this->cards->item($this->cards->parse($row['data']));
                } catch (ToolFailure) {
                    continue;
                }
                foreach ($contact['categories'] ?? [] as $name) {
                    $key = mb_strtolower(trim($name));
                    if ($key === '') {
                        continue;
                    }
                    if (!isset($groups[$key])) {
                        $groups[$key] = ['name' => trim($name), 'contactCount' => 0];
                    }
                    $groups[$key]['contactCount']++;
                }
            }
        }
        $groups = array_values($groups);
        usort($groups, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
        $offset = $arguments['offset'] ?? 0;
        $limit = $arguments['limit'] ?? 100;
        $page = array_slice($groups, $offset, $limit + 1);
        $more = count($page) > $limit;
        $page = array_slice($page, 0, $limit);
        return ToolResult::json([
            'groups' => $page,
            'hasMore' => $more,
            'nextOffset' => $more ? $offset + count($page) : null,
        ]);
    }
}
