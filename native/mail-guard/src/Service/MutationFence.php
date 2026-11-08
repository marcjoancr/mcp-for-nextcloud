<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
/** Narrow durable stage admission. Implementations must do DB work only and never receive an IMAP client. */
interface MutationFence {
    public function beforePreconditions(array $binding): bool;
    public function beforeWire(array $binding): bool;
    public function recordRemote(array $proof): bool;
    public function beforeCache(array $binding): bool;
    public function recordCache(array $result): bool;
}
