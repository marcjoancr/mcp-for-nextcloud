<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Runs in the disposable container before enabling the verified Contacts archive; never force compatibility.
[$script, $path, $major, $version] = $argv;
$xml = simplexml_load_file($path);
$min = $xml === false ? '' : (string)$xml->dependencies->nextcloud['min-version'];
$max = $xml === false ? '' : (string)$xml->dependencies->nextcloud['max-version'];
if ($xml === false || (string)$xml->id !== 'contacts' || (string)$xml->version !== $version
    || $min === '' || $max === '' || version_compare($major, $min, '<') || version_compare($major, $max, '>')) {
    fwrite(STDERR, "Contacts manifest does not match pinned version $version / Nextcloud $major\n");
    exit(1);
}
echo "Contacts manifest verified: $version / Nextcloud $major ($min–$max)\n";
