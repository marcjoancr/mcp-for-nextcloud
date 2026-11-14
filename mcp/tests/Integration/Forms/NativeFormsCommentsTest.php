<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsCommentsTest extends TestCase {
    private function probe(string $file): void {
        if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {$this->markTestSkipped('Requires disposable native cell');}
        $process=proc_open([PHP_BINARY,'/tmp/'.$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$error.' '.$out);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok'],json_encode($result));
        self::assertNotEmpty($result['checks']);
        foreach ($result['checks'] as $name=>$passed) {self::assertTrue($passed,$name);}
    }
    public function testGlobalAndFormCommentSwitchesProtectOriginalMcpReadsWritesAndEvents(): void {$this->probe('comments-global-probe.php');}
    public function testRolledBackFullEventBudgetAllowsSameProcessSave(): void {$this->probe('comments-rollback-budget-probe.php');}
    public function testRealCommentSqlDefersBothConsumersAndClearsRollbackCache(): void {$this->probe('comments-probe.php');}
    public function testRealDavReadMarkersCrudAndPostWaitAuthority(): void {$this->probe('comments-dav-probe.php');}
    public function testNativeAuthorOriginalMcpAuthorityAndOneShotDelivery(): void {$this->probe('comments-policy-probe.php');}
    public function testFreshGlobalFactoryInstallRemovalReactivationAndRollback(): void {$this->probe('comments-lifecycle-matrix.php');}
}
