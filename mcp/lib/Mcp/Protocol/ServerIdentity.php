<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Protocol;

use OCP\App\IAppManager;
use OCA\Mcp\Release\SourceOffer;
use OCA\Mcp\Mcp\Tools\Discovery\ToolPresentation;

/** One installed-app identity for every protocol response; Nextcloud caches the manifest lookup. */
final class ServerIdentity {
    public function __construct(private IAppManager $apps, private SourceOffer $sourceOffer) {}

    /** Shared release metadata supplements discovery without adding a capability grant. */
    public function instructions(): string {
        $offer = $this->sourceOffer->get();
        if ($offer === null) {
            return ToolPresentation::INSTRUCTIONS . "\n\nSource delivery is unavailable. Contact your administrator.";
        }
        $lines = [ToolPresentation::INSTRUCTIONS, '',
            'Corresponding source for Nextcloud MCP ' . $offer['version'] . ' (commit ' . $offer['sourceCommit'] . ').',
            'Download these release files without sending Nextcloud credentials. Keep the complete kit together.'];
        foreach ($offer['files'] as $name => $url) { $lines[] = $name . ': ' . $url; }
        $lines[] = 'Expected manifest SHA-256: ' . $offer['manifestSha256'];
        return implode("\n", $lines);
    }

    /** @return array{name:string, version:string} */
    public function info(): array {
        return ['name' => 'mcp-for-cloud', 'version' => $this->apps->getAppVersion('mcp')];
    }
}
