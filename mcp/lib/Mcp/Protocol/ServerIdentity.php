<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Protocol;

use OCP\App\IAppManager;

/** One installed-app identity for every protocol response; Nextcloud caches the manifest lookup. */
final class ServerIdentity {
    public function __construct(private IAppManager $apps) {}

    /** @return array{name:string, version:string} */
    public function info(): array {
        return ['name' => 'mcp-for-cloud', 'version' => $this->apps->getAppVersion('mcp')];
    }
}
