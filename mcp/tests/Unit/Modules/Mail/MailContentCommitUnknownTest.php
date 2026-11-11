<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailContentCommitUnknownTest extends TestCase {
    public function testFailedConnectionDiscardCannotBecomeAuthoritativeOutcomeEvidence():void {require_once dirname(__DIR__,5).'/native/mail-guard/src/Exception/ContentCommitUnknown.php';self::assertTrue(method_exists('OCA\\Mail\\Exception\\ContentCommitUnknown','discard'),'A failed close must retain the unknown connection state.');$db=$this->createMock(\OCP\IDBConnection::class);$db->method('close')->willThrowException(new \RuntimeException('Synthetic close failure'));try{\OCA\Mail\Exception\ContentCommitUnknown::discard($db);self::fail('Unknown outcome must interrupt normal completion.');}catch(\OCA\Mail\Exception\ContentCommitUnknown $error){self::assertFalse($error->connectionDiscarded);}}
}
