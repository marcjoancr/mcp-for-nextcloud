<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Deck\Files\Linking;
use OCA\Mcp\Tests\Integration\Deck\DeckFixture;
use PHPUnit\Framework\TestCase;

final class DeckFixtureRuntimeTest extends TestCase {
    public function testFreshActorLoadsTheTestRuntimeWithoutRunningItsCli(): void {
        $fixture = dirname(__DIR__, 5) . '/Integration/Deck/DeckFixture.php';
        $autoload = dirname(__DIR__, 6) . '/vendor/autoload.php';
        $script = 'require ' . var_export($fixture, true) . '; $before=class_exists("PHPUnit\\Framework\\TestCase",false);'
            . '\OCA\Mcp\Tests\Integration\Deck\DeckFixture::loadProbeRuntime(' . var_export($autoload, true) . ');'
            . '$available=class_exists("PHPUnit\\Framework\\TestCase",false);'
            . 'if(!$available){echo json_encode(["before"=>$before,"available"=>$available]);exit;}'
            . '$probe=new class("actorProbe") extends \PHPUnit\Framework\TestCase {public function mockWorks():bool{return $this->createMock(\stdClass::class) instanceof \stdClass;}};'
            . '\OCA\Mcp\Tests\Integration\Deck\DeckFixture::loadProbeRuntime("/missing-runtime-must-not-be-read");'
            . 'echo json_encode(["before"=>$before,"available"=>$available,"mockWorks"=>$probe->mockWorks()]);';
        $process=proc_open([PHP_BINARY,'-r',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$errors);
        self::assertSame(['before'=>false,'available'=>true,'mockWorks'=>true],json_decode($output,true,512,JSON_THROW_ON_ERROR),'Only the actor result may appear; the test CLI must not execute');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testAbsentCirclesNeverResolvesItsOptionalNativeManager(): void {
        self::assertFalse(class_exists('OCA\\Circles\\CirclesManager',false));
        $failure=null;
        try{DeckFixture::clearCirclesSession();}catch(\Throwable $error){$failure=$error::class;}
        self::assertNull($failure,'A plain source with Circles disabled must not resolve or require its manager');
        self::assertFalse(class_exists('OCA\\Circles\\CirclesManager',false));
    }
}
