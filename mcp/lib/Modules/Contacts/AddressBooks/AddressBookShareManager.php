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
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IUserManager;
use OCP\Share\IShare;

final class AddressBookShareManager
{
    private const PRINCIPAL_PREFIX = 'principals/users/';
    private const PRINCIPAL_HREF_PREFIX = 'principal:';

    public function __construct(
        private ContactAccess $access,
        private ContactStore $store,
        private IUserManager $users,
        private ContactDav $dav,
        private ISearch $search,
    ) {
    }

    private function ownedBook(string $path, string $userId, bool $write): object
    {
        if ($this->access->isSystemPath($userId, $path)) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
        $book = $this->access->resolve($userId, $path, $write);
        if ($book->ownerId !== $userId) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        return $book;
    }

    /** @return list<array<string, mixed>> */
    private function visibleShares(int $bookId): array
    {
        $shares = [];
        foreach ($this->store->shares($bookId) as $share) {
            $principal = (string) ($share['{http://owncloud.org/ns}principal'] ?? '');
            $href = (string) ($share['href'] ?? '');
            if ($principal === '' && str_starts_with($href, self::PRINCIPAL_HREF_PREFIX)) {
                $principal = self::PRINCIPAL_PREFIX . substr($href, strlen(self::PRINCIPAL_HREF_PREFIX));
            }
            if (!str_starts_with($principal, self::PRINCIPAL_PREFIX)) {
                continue;
            }
            $userId = substr($principal, strlen(self::PRINCIPAL_PREFIX));
            if ($userId === '') {
                continue;
            }
            $shares[] = [
                'userId' => $userId,
                'displayName' => (string) ($share['commonName'] ?? $userId),
                'readOnly' => (bool) ($share['readOnly'] ?? true),
                'status' => (int) ($share['status'] ?? 0),
            ];
        }
        return $shares;
    }

    public function list(array $arguments, string $userId): array
    {
        $book = $this->ownedBook($arguments['addressbook'], $userId, false);
        return ToolResult::json([
            'addressbook' => $book,
            'shares' => $this->visibleShares($book->id),
        ]);
    }

    private function prepare(array $arguments, string $userId): array
    {
        $book = $this->ownedBook($arguments['addressbook'], $userId, true);
        $shareeId = trim((string) ($arguments['shareWith'] ?? ''));
        if ($shareeId === '' || $shareeId === $userId || (preg_match('/[\x00-\x1f\x7f]/', $shareeId) || str_contains($shareeId, '/') || str_contains($shareeId, '\\'))) {
            throw new InvalidArgumentException(Translator::t('Choose another valid Nextcloud user to share with.'));
        }
        $removing = $arguments['access'] === 'remove';
        if (!$removing && !$this->recipientVisible($shareeId)) {
            throw new InvalidArgumentException(Translator::t('The recipient is not an available Nextcloud user.'));
        }
        $sharee = $this->users->get($shareeId);
        if (!$removing && ($sharee === null || !$sharee->isEnabled() || $sharee->getUID() !== $shareeId)) {
            throw new InvalidArgumentException(Translator::t('The recipient is not an available Nextcloud user.'));
        }
        $existing = null;
        foreach ($this->visibleShares($book->id) as $share) {
            if ($share['userId'] === $shareeId) {
                $existing = $share;
                break;
            }
        }
        if ($removing && $existing === null) {
            throw new InvalidArgumentException(Translator::t('The address book is not shared with this user.'));
        }
        $readOnly = $removing ? null : $arguments['access'] === 'read';
        if (!$removing && $existing !== null && $existing['readOnly'] === $readOnly) {
            throw new InvalidArgumentException(Translator::t('The address book is already shared with this access.'));
        }
        return [
            'addressbook' => (array) $book,
            'recipient' => [
                'userId' => $shareeId,
                'displayName' => ($sharee?->getDisplayName() ?: null) ?: ($existing['displayName'] ?? $shareeId),
            ],
            'access' => $removing ? 'remove' : ($readOnly ? 'read-only' : 'read-write'),
            'readOnly' => $readOnly,
            'existingAccess' => $existing === null ? null : ($existing['readOnly'] ? 'read-only' : 'read-write'),
            'action' => $removing ? 'remove_share' : 'share_addressbook',
            'requiresConfirmation' => true,
            'willWrite' => !$removing,
            'willDelete' => $removing,
        ];
    }

    private function recipientVisible(string $uid): bool
    {
        [$result] = $this->search->search($uid, [IShare::TYPE_USER], false, 10, 0);
        foreach ($result['exact']['users'] ?? [] as $candidate) {
            if (($candidate['value']['shareWith'] ?? null) === $uid) {
                return true;
            }
        }
        return false;
    }

    public function preview(array $arguments, string $userId): array
    {
        return $this->prepare($arguments, $userId);
    }

    public function share(array $arguments, string $userId): array
    {
        $plan = $this->prepare($arguments, $userId);
        if (($arguments['confirm'] ?? false) !== true) {
            throw new InvalidArgumentException(Translator::t('Confirm the address book share after reviewing its recipient and access.'));
        }
        $this->dav->shareAddressBook(
            $userId,
            $plan['addressbook']['uri'],
            $plan['recipient']['userId'],
            $plan['recipient']['displayName'],
            $plan['readOnly']
        );
        if ($plan['readOnly'] === null) {
            foreach ($this->visibleShares($plan['addressbook']['id']) as $share) {
                if ($share['userId'] === $plan['recipient']['userId']) {
                    throw new ToolFailure(Translator::t('The share removal was submitted but remains in the address book ACL.'));
                }
            }
            return ToolResult::json([
                'addressbook' => $plan['addressbook'],
                'userId' => $plan['recipient']['userId'],
                'status' => 'removed',
            ]);
        }
        foreach ($this->visibleShares($plan['addressbook']['id']) as $share) {
            if ($share['userId'] === $plan['recipient']['userId'] && $share['readOnly'] === $plan['readOnly']) {
                return ToolResult::json([
                    'share' => $share,
                    'access' => $plan['access'],
                    'addressbook' => $plan['addressbook'],
                    'status' => $plan['existingAccess'] === null ? 'shared' : 'updated',
                ]);
            }
        }
        throw new ToolFailure(Translator::t('The share was submitted but could not be verified in the address book ACL.'));
    }
}
