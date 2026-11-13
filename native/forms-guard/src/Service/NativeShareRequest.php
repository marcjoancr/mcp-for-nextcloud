<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCP\Server;
/** Native UI authority is valid only in the capturing authenticated request. */
final class NativeShareRequest
{
    private static array $requests = [];
    private static function credentialDigest(string $uid): string
    {
        $passwordHash = Server::get(\OCP\IDBConnection::class)->executeQuery('SELECT password FROM *PREFIX*users WHERE uid = ?', [$uid])->fetchOne();
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new \RuntimeException('Native sharing account is unavailable');
        }
        return hash_hmac('sha256', json_encode([$uid, $passwordHash], JSON_THROW_ON_ERROR), Server::get(\OCP\IConfig::class)->getSystemValueString('secret'));
    }
    public static function capture(): array
    {
        $user = Server::get(\OCP\IUserSession::class)->getUser();
        if ($user === null) {
            throw new \RuntimeException('Original native sharing session is unavailable');
        }
        $uid = $user->getUID();
        $nonce = bin2hex(random_bytes(32));
        self::$requests[$nonce] = ['uid' => $uid, 'user' => $user, 'credential_digest' => self::credentialDigest($uid)];
        $proof = ['uid' => $uid, 'request' => $nonce];
        self::assertCurrent($proof);
        return $proof;
    }
    public static function assertCurrent(array $proof): void
    {
        $captured = self::$requests[$proof['request'] ?? ''] ?? null;
        if ($captured === null || $captured['uid'] !== ($proof['uid'] ?? null) || Server::get(\OCP\IUserSession::class)->getUser() !== $captured['user']) {
            throw new \RuntimeException('Original native sharing request is unavailable');
        }
        $core = new FreshCore($captured['uid']);
        if (!$core->apps()->isEnabledForUser('forms', $core->user()) || !hash_equals($captured['credential_digest'], self::credentialDigest($captured['uid']))) {
            throw new \RuntimeException('Original native sharing account changed');
        }
    }
}
