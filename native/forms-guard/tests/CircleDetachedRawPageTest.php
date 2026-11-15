<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\CircleDetached\RawPage;
if(!class_exists(\OCA\Forms\Mcp\Service\CircleReadData::class,false)){require_once dirname(__DIR__).'/src/Service/CircleReadData.php';}
require_once dirname(__DIR__).'/src/Service/CircleDetached/RawPage.php';
final class CircleDetachedRawPageTest extends TestCase {
    public function testAbsentOuterJoinPreservesEveryOriginalColumn():void {
        $rows=[['unique_id'=>str_repeat('a',31),'settings'=>'[]','circle_based_on_unique_id'=>null,'circle_based_on_name'=>null,'circle_based_on_settings'=>null]];
        self::assertSame($rows,RawPage::validate($rows));
    }
    public function testPresentJoinCannotHideMalformedSettings():void {
        $this->expectException(RuntimeException::class);
        RawPage::validate([['owner_unique_id'=>str_repeat('a',31),'owner_settings'=>null]]);
    }
    public function testAbsentJoinCannotContainOtherBoundData():void {
        $this->expectException(RuntimeException::class);
        RawPage::validate([['owner_unique_id'=>null,'owner_settings'=>null,'owner_source'=>1]]);
    }
    public function testLegacySettingsRejectWholePage():void {
        $this->expectException(RuntimeException::class);
        RawPage::validate([['settings'=>'[]'],['circle_settings'=>'{"password_single":"legacy"}']]);
    }
    public function testSqlOverflowMarkerRefusesBeforeImport():void {
        $this->expectException(RuntimeException::class);
        RawPage::validate([['description'=>'__forms_circle_row_budget_exceeded__']]);
    }
    public function testCumulativeRawBudgetStillApplies():void {
        $this->expectException(RuntimeException::class);
        RawPage::validate([['description'=>str_repeat('x',600000)],['description'=>str_repeat('y',600000)]]);
    }
}
