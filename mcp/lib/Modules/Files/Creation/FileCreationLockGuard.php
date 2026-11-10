<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IConfig;

/** Requires the qualified web-worker DB locking configuration for atomic file-name creation. */
final class FileCreationLockGuard {
    public function __construct(private IConfig $config, private string $sapi = PHP_SAPI) {}

    /** Refuses creation unless the FPM-compatible DB provider is enabled and no path bypasses it. */
    public function assertAvailable(): void {
        // Nextcloud's DBLockingProvider is enabled by default; use its native default when the key is unset.
        $enabled = $this->config->getSystemValue('filelocking.enabled', true);
        $backend = $this->config->getSystemValue('memcache.locking', '');
        $debug = $this->config->getSystemValue('filelocking.debug', false);
        $excluded = $this->config->getSystemValue('filelocking.excluded', []);
        $webSapis = ['fpm-fcgi', 'apache2handler', 'cgi-fcgi'];

        // Only the default database provider has been verified to support the exclusive-parent/shared-child
        // nesting used by Nextcloud's filesystem API. The provider disables its shared-lock cache under CLI, so
        // background workers and unknown SAPIs are not admitted either. Other lock backends remain unqualified.
        if (!in_array($this->sapi, $webSapis, true) || $enabled !== true || $backend !== '' || $debug !== false || $excluded !== []) {
            throw new ToolFailure(CreationMessages::lockingUnavailable());
        }
    }
}
