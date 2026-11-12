<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Exception;
/** Recovery may use persisted evidence only after the authoritative connection was discarded. */
final class ContentCommitUnknown extends \RuntimeException {
    public function __construct(public readonly bool $connectionDiscarded){parent::__construct('Native content commit outcome unavailable.');}
    public static function discard(\OCP\IDBConnection $db):never {$discarded=true;try{$db->close();}catch(\Throwable){$discarded=false;}throw new self($discarded);}
}
