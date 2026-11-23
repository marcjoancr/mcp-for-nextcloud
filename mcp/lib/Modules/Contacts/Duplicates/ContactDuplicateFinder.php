<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Duplicates;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use Sabre\VObject\Component\VCard;

final class ContactDuplicateFinder {
    private const MAX_CARDS = 10000;
    private const MAX_GROUP_MEMBERS = 100;

    public function __construct(
        private ContactAccess $access,
        private ContactStore $store,
        private ContactCard $cards,
    ) {}

    /** @param array{addressbook?:string,limit?:int,offset?:int} $arguments */
    public function execute(array $arguments, string $userId): array {
        $books = isset($arguments['addressbook'])
            ? [$this->access->resolve($userId, $arguments['addressbook'])]
            : $this->access->visible($userId);
        $seen = 0;
        $invalid = 0;
        $byKey = [];
        foreach ($books as $book) {
            $rows = $this->store->cards($book->id);
            $seen += count($rows);
            if ($seen > self::MAX_CARDS) {
                throw new ToolFailure(Translator::t('Too many contacts to scan. Select a single address book.'));
            }
            foreach ($rows as $row) {
                try {
                    $card = $this->cards->parse($row['data']);
                } catch (ToolFailure) {
                    $invalid++;
                    continue;
                }
                $item = [
                    'name' => (string)$card->FN,
                    'uid' => (string)$card->UID,
                    'addressbook' => $book->path,
                    'uri' => $row['uri'],
                    'etag' => $row['etag'],
                    'writable' => $book->writable,
                ];
                foreach ($this->keys($card) as $key) {
                    $byKey[$key][$book->path . $row['uri']] = $item;
                }
            }
        }

        ksort($byKey);
        $matches = [];
        foreach ($byKey as $key => $contacts) {
            if (count($contacts) < 2) {
                continue;
            }
            [$reason, $value] = explode(':', $key, 2);
            ksort($contacts);
            $matches[] = [
                'reason' => $reason,
                'value' => $reason === 'name_and_organization' ? str_replace("\0", ' / ', $value) : $value,
                'count' => count($contacts),
                'truncated' => count($contacts) > self::MAX_GROUP_MEMBERS,
                'contacts' => array_slice(array_values($contacts), 0, self::MAX_GROUP_MEMBERS),
            ];
        }
        $offset = $arguments['offset'] ?? 0;
        $limit = $arguments['limit'] ?? 20;
        return ToolResult::json([
            'groups' => array_slice($matches, $offset, $limit),
            'nextOffset' => $offset + $limit < count($matches) ? $offset + $limit : null,
            'skippedUnreadable' => $invalid,
        ]);
    }

    /** @return list<string> */
    private function keys(VCard $card): array {
        $keys = [];
        foreach ($card->select('EMAIL') as $email) {
            $value = strtolower(trim((string)$email));
            if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                $keys[] = 'email:' . $value;
            }
        }
        foreach ($card->select('TEL') as $phone) {
            $value = preg_replace('/^tel:/i', '', trim((string)$phone)) ?? '';
            if (preg_match('/^\+?[0-9 ().-]+$/D', $value) !== 1) {
                continue;
            }
            $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
            if (str_starts_with($digits, '00')) {
                $digits = '+' . substr($digits, 2);
            } elseif (str_starts_with($value, '+')) {
                $digits = '+' . $digits;
            }
            if (strlen(ltrim($digits, '+')) >= 7) {
                $keys[] = 'phone:' . $digits;
            }
        }
        $name = $this->normalizedLabel((string)($card->FN ?? ''));
        $organization = $this->normalizedLabel((string)($card->ORG ?? ''));
        if ($name !== '' && $organization !== '') {
            $keys[] = 'name_and_organization:' . $name . "\0" . $organization;
        }
        return array_values(array_unique($keys));
    }

    private function normalizedLabel(string $value): string {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    }
}
