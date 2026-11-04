<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** Loads the supported @nextcloud/files sidebar extension only on Files pages. */
final class FilesDescriptionSidebarListener implements IEventListener {
    public const EVENT = 'OCA\\Files\\Event\\LoadAdditionalScriptsEvent';

    public function handle(Event $event): void {
        if (!is_a($event, self::EVENT)) return;
        Util::addScript('mcp', 'files');
    }
}
