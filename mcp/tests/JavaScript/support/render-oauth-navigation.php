<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

/** Render the production navigation template for controlled local browser destinations. */
function p(mixed $text): void {
    echo htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$l = new class {
    public function t(string $text): string { return $text; }
};
$_ = ['target' => $argv[1]];
require dirname(__DIR__, 3) . '/templates/oauth-redirect.php';
