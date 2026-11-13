<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;

use PHPUnit\Framework\TestCase;
final class NativeFormsAnswerPreflightTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {
            $this->markTestSkipped('Requires disposable native cell');
        }
    }
    private function runCase(string $case): array
    {
        $p = proc_open([PHP_BINARY, '/tmp/answer-preflight-probe.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
    public function testAllNativeFamiliesAndRangesValidateWithoutPreviewEffects(): void
    {
        $r = $this->runCase('matrix');
        self::assertSame(13, $r['families']);
        self::assertSame(15, $r['questions']);
        self::assertSame(15, $r['validated_question_count']);
        self::assertTrue($r['preview_unchanged']);
        self::assertSame(['2026-11-13', '2026-11-14'], array_values($r['date_values']));
        self::assertSame(['09:30', '16:45'], array_values($r['time_values']));
    }
    public function testSemanticFailuresRefuseWithoutEffects(): void
    {
        $r = $this->runCase('invalid');
        self::assertSame(['date' => true, 'ranking' => true, 'empty_scalar' => true, 'required_other' => true, 'foreign_other' => true], $r['rejected']);
        self::assertTrue($r['unchanged']);
    }
    public function testRepeatedFilePreviewsDoNotConsumeAndUpdatesNeedNewHandles(): void
    {
        foreach ($this->runCase('files') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testSubmissionCapAndForeignResponseFailBeforeEffects(): void
    {
        foreach ($this->runCase('limits') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testNativeRevisionChangeRefusesPreflight(): void
    {
        foreach ($this->runCase('stale') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testClonePreviewRequiresNativeEditAndCreationPolicy(): void
    {
        foreach ($this->runCase('clone') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testChangedAndExpiredFilesFailReadOnlyInspection(): void
    {
        foreach ($this->runCase('file_invalid') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testNativeSingleSubmissionRuleRejectsRepeatBeforeReservation(): void
    {
        foreach ($this->runCase('unique') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testAnonymousRowsCannotBeClaimedByOwnResponseTool(): void
    {
        foreach ($this->runCase('own_anonymous') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
}
