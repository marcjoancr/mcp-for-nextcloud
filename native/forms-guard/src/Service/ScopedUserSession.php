<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
use OCP\{IUser,IUserSession};
/** Read-only native actor view. Never modifies the worker's global/session storage. */
final class ScopedUserSession implements IUserSession {
    public function __construct(private IUser $user) {}
    public function getUser() {return $this->user;}
    public function isLoggedIn() {return true;}
    public function getImpersonatingUserID(): ?string {return null;}
    public function login($uid,$password) {throw new \LogicException('Read-only actor scope');}
    public function logout() {throw new \LogicException('Read-only actor scope');}
    public function setUser($user) {throw new \LogicException('Read-only actor scope');}
    public function setVolatileActiveUser(?IUser $user): void {throw new \LogicException('Read-only actor scope');}
    public function setImpersonatingUserID(bool $useCurrentUser=true): void {throw new \LogicException('Read-only actor scope');}
}
