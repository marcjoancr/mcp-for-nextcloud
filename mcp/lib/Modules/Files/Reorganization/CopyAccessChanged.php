<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

/** Abort a listed copy without returning names whose authorization has changed. */
final class CopyAccessChanged extends ToolFailure {
    public function __construct() {
        parent::__construct(FileAccessMessages::notFound() . ' ' . ReorganizationMessages::copyAccessChanged());
    }
}
