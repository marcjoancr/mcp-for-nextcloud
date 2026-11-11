<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Access\ResourceScope\MailResourceInventory;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
/** Native ownership, quarantine and SQL bounds; no gateway, saved policy, credentials or network. */
final class NativeMailResourceInventory implements MailResourceInventory {
    public function __construct(private MailServices $native){}
    public function resources(string $uid):array {
        if(!$this->native->isEnabledFor($uid))throw new PolicyUnavailable('Native Mail resources unavailable');
        return $this->native->boundedMetadata($uid)->resourceInventory($uid);
    }
}
