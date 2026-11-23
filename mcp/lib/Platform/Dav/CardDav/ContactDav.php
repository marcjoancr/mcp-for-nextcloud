<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Platform\Dav\CardDav;

use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use RuntimeException;
/** Native CardDAV writes. Deletion is permanent in core; callers must verify a backup first. */
interface ContactDav
{
    /**
     * Creates an address book through native CardDAV extended MKCOL.
     *
     * @param string $userId authenticated user UID
     * @param string $uri address-book URI in the acting user's home
     * @param string $displayName human-readable address-book name
     * @return DavResult
     * @throws ToolFailure when native DAV rejects the path, ACL, metadata or precondition
     * @throws RuntimeException when the authenticated session or native response is unexpected
     */
    public function createAddressBook(string $userId, string $uri, string $displayName): DavResult;
    /**
     * Adds or updates a local user share through Nextcloud's native CardDAV sharing POST.
     *
     * @param string $userId authenticated user UID
     * @param string $bookUri address-book URI in the acting user's home
     * @param string $shareeId local Nextcloud user ID
     * @param string $displayName sharee display name for the native invite
     * @param bool|null $readOnly whether the share is read-only, or null to remove the share
     * @return DavResult
     * @throws ToolFailure when native DAV refuses the share or ACL
     * @throws RuntimeException when the session or native response is unexpected
     */
    public function shareAddressBook(string $userId, string $bookUri, string $shareeId, string $displayName, ?bool $readOnly): DavResult;
    /**
     * Creates a contact through native CardDAV without overwriting an existing object.
     *
     * @param string $userId authenticated user UID
     * @param string $bookUri address-book URI in the acting user home
     * @param string $uri contact object URI within the address book
     * @param string $vcard complete serialized vCard
     * @return DavResult
     * @throws ToolFailure when native DAV rejects the path, ACL, data or precondition
     * @throws RuntimeException when the authenticated session or native response is unexpected
     */
    public function put(
        string $userId,
        string $bookUri,
        string $uri,
        string $vcard,
    ): DavResult;
    /**
     * Updates a contact through native CardDAV using its current ETag.
     *
     * @param string $userId authenticated user UID
     * @param string $bookUri address-book URI in the acting user home
     * @param string $uri contact object URI within the address book
     * @param string $etag current object ETag required by If-Match
     * @param string $vcard complete serialized vCard
     * @return DavResult
     * @throws ToolFailure when native DAV rejects the path, ACL, data or precondition
     * @throws RuntimeException when the authenticated session or native response is unexpected
     */
    public function update(
        string $userId,
        string $bookUri,
        string $uri,
        string $etag,
        string $vcard,
    ): DavResult;
    /**
     * Permanently deletes a contact through native CardDAV after the caller verifies its backup.
     *
     * @param string $userId authenticated user UID
     * @param string $bookUri address-book URI in the acting user home
     * @param string $uri contact object URI within the address book
     * @param string $etag current object ETag required by If-Match
     * @return DavResult
     * @throws ToolFailure when native DAV rejects the path, ACL, data or precondition
     * @throws RuntimeException when the authenticated session or native response is unexpected
     */
    public function delete(
        string $userId,
        string $bookUri,
        string $uri,
        string $etag,
    ): DavResult;

}
