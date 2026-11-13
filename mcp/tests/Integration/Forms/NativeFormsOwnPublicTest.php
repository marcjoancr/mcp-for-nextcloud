<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;

use PHPUnit\Framework\TestCase;
final class NativeFormsOwnPublicTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {
            $this->markTestSkipped('Requires disposable native cell');
        }
    }
    private function runCase(string $case): array
    {
        $p = proc_open([PHP_BINARY, '/tmp/own-public-probe.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(compact('case')));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($p), $err);
        $r = json_decode($out, true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($r['ok'], json_encode($r));
        return $r['result'];
    }
    private function allTrue(string $case): void
    {
        foreach ($this->runCase($case) as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testRemovedShareAndExpiredOrArchivedPriorSubmitterMatchesNativeResults(): void
    {
        $this->allTrue('prior');
    }
    public function testOwnerStillGetsOnlyOwnRowsAndAnonymousAndNoPriorRowsAreRefused(): void
    {
        $this->allTrue('ownership');
    }
    public function testOwnAnswersContinueAfterFiftyWithoutRepeats(): void
    {
        $r = $this->runCase('answers');
        self::assertSame(50, $r['first']);
        self::assertSame(11, $r['second']);
        self::assertSame(61, $r['unique']);
        self::assertTrue($r['ordered']);
        self::assertTrue($r['plural_prefix']);
        self::assertSame(['offset' => 0, 'hasMore' => true, 'nextOffset' => 50], $r['first_page']);
        self::assertSame(['offset' => 50, 'hasMore' => false, 'nextOffset' => null], $r['last_page']);
    }
    public function testEqualTimestampSubmissionsHaveStableAccuratePages(): void
    {
        $r = $this->runCase('stable');
        self::assertTrue($r['stable']);
        self::assertTrue($r['exact_last_page']);
        self::assertTrue($r['query']);
        self::assertSame([['offset' => 0, 'hasMore' => true, 'nextOffset' => 2], ['offset' => 2, 'hasMore' => true, 'nextOffset' => 4], ['offset' => 4, 'hasMore' => false, 'nextOffset' => null]], $r['pages']);
    }
    public function testOwnFileReferencesPageAboveOneHundredAndDenyForeignRows(): void
    {
        $r = $this->runCase('files');
        self::assertTrue($r['private_exact']);
        self::assertTrue($r['ordinary_file_id_ignored']);
        self::assertSame(100, $r['first']);
        self::assertSame(1, $r['last']);
        self::assertTrue($r['own']);
        self::assertTrue($r['foreign_refused']);
        self::assertSame(['offset' => 0, 'hasMore' => true, 'nextOffset' => 100], $r['first_page']);
        self::assertSame(['offset' => 100, 'hasMore' => false, 'nextOffset' => null], $r['last_page']);
    }
    public function testPublicHashDiscoversOnlyCurrentSubmissionSchemaWithoutEffects(): void
    {
        $this->allTrue('public');
    }
    public function testSixtyThreePublicQuestionsAndOptionsContinueWithExactNativeIds(): void
    {
        $this->allTrue('public_large');
    }
    public function testPublicDiscoverySuppliesNativeChoiceRankingAndGridSubmissionIds(): void
    {
        $this->allTrue('workflow');
    }
    public function testSnapshotsDoNotEagerLoadCollectionsAndReadQueriesUseLimitPlusOne(): void
    {
        $this->allTrue('bounded');
    }
    public function testEverySelectedResponseMatchesFormEvenWithFullResultsPermission(): void
    {
        $this->allTrue('scope');
    }
    public function testByteBudgetsRefuseOversizedRowsWhilePreviewStaysBounded(): void
    {
        $this->allTrue('bytes');
    }
    public function testOriginalReadGrantsAreFreshAtEveryNativePageAndSnapshot(): void
    {
        $this->allTrue('fresh');
    }
    public function testExistingOwnEditAndDeleteNativePredicatesStayIntact(): void
    {
        $this->allTrue('edit');
    }
}
