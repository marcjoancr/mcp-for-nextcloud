<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
/** DB-only admission; read observation is not cache dispatch. Never receives the private client. */
interface RefreshFence {
    public function authorizeRead(array $binding):bool;
    public function beforeCache(array $binding,array $observation):bool;
    public function recordCache(array $result):bool;
}
