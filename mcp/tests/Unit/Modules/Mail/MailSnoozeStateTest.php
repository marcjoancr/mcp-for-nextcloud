<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailSnoozeStateTest extends TestCase {
    private function load():void {$file=dirname(__DIR__,5).'/native/mail-guard/src/Snooze/SnoozeState.php';self::assertFileExists($file,'Guarded snooze needs a closed non-replayable state machine.');require_once $file;}
    public function testUnknownWireNeverBecomesExecutableOrReleasesItsHold():void {
        $this->load();
        foreach(['placement','wake']as$leg){
            $row=['state'=>$leg==='placement'?'placing':'waking','placement_phase'=>$leg==='placement'?'wire_may_start':'completed','wake_phase'=>$leg==='wake'?'wire_may_start':'prepared'];
            self::assertFalse(\OCA\Mail\Snooze\SnoozeState::cancellable($row));
            self::assertFalse(\OCA\Mail\Snooze\SnoozeState::releasable($row));
            self::assertSame('uncertain',\OCA\Mail\Snooze\SnoozeState::visible($row));
        }
        self::assertFalse(\OCA\Mail\Snooze\SnoozeState::executable(['state'=>'uncertain','placement_phase'=>'wire_may_start','wake_phase'=>'prepared']));
    }
    public function testOnlyProvenPlacementCanBeCancelledWithoutMoving():void {
        $this->load();$row=['state'=>'snoozed','placement_phase'=>'remote_verified','wake_phase'=>'prepared'];
        self::assertTrue(\OCA\Mail\Snooze\SnoozeState::cancellable($row));self::assertTrue(\OCA\Mail\Snooze\SnoozeState::releasable($row));self::assertTrue(\OCA\Mail\Snooze\SnoozeState::executable($row));
        foreach(['woken','cancelled','blocked','uncertain']as$state)self::assertFalse(\OCA\Mail\Snooze\SnoozeState::executable(array_replace($row,['state'=>$state])));
    }
}
