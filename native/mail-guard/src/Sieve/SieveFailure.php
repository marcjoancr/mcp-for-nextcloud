<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Sieve;
/** Never chain a provider exception: status text can contain credentials or configuration. */
final class SieveFailure extends \RuntimeException {
    public function __construct(){parent::__construct('Sieve protocol refused.');}
}
