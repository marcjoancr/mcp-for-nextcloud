<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsCommentsActivationTest extends TestCase {
    public function testReadOnlyActivationPreservesEveryIntegrityRejection():void {
        $file=dirname(__DIR__,5).'/native/forms-guard/tests/comments-activation-readonly.php';
        $process=proc_open([PHP_BINARY,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$error.' '.$out);
        $result=json_decode($out,true,32,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);self::assertCount(16,$result['checks']);
        foreach($result['checks']as$name=>$passed)self::assertTrue($passed,$name);
    }
}
