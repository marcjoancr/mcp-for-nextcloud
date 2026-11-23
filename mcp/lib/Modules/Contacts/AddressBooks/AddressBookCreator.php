<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Contacts\AddressBooks;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

final class AddressBookCreator
{
    public function __construct(private ContactAccess $access, private ContactDav $dav)
    {
    }

    private function prepare(array $arguments, string $userId): array
    {
        $name = trim((string) ($arguments['displayName'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
            throw new InvalidArgumentException(Translator::t('Enter a valid address book name.'));
        }
        $uri = $this->uri($name);
        foreach ($this->access->visible($userId) as $book) {
            if ($book->ownerId === $userId && $book->uri === $uri) {
                throw new InvalidArgumentException(Translator::t('An address book with this name already exists.'));
            }
        }
        return [
            'name' => $name,
            'uri' => $uri,
            'path' => '/remote.php/dav/addressbooks/users/' . rawurlencode($userId) . '/' . rawurlencode($uri) . '/',
            'action' => 'create_addressbook',
            'willWrite' => true,
        ];
    }

    private function uri(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $ascii === false ? '' : $ascii) ?? '');
        $slug = trim(substr($slug, 0, 80), '-');
        return $slug !== '' ? $slug : 'addressbook-' . substr(hash('sha256', $name), 0, 12);
    }

    public function preview(array $arguments, string $userId): array
    {
        return $this->prepare($arguments, $userId);
    }

    public function execute(array $arguments, string $userId): array
    {
        $plan = $this->prepare($arguments, $userId);
        if (($arguments['confirm'] ?? false) !== true) {
            throw new InvalidArgumentException(Translator::t('Confirm address book creation after reviewing its plan.'));
        }
        $this->dav->createAddressBook($userId, $plan['uri'], $plan['name']);
        foreach ($this->access->visible($userId) as $book) {
            if ($book->ownerId === $userId && $book->uri === $plan['uri']) {
                return ToolResult::json([
                    'addressbook' => $book,
                    'status' => 'created',
                ]);
            }
        }
        throw new ToolFailure(Translator::t('The address book was created but could not be verified.'));
    }
}
