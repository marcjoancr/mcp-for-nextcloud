<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCP\{IDBConnection,IConfig,DB\IResult};
use PHPUnit\Framework\TestCase;
final class PgDirectConnectionTest extends TestCase {
    private function proof(string $configured,string $actual,string $port='5432'):bool {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/PgDirectConnection.php';
        $db=$this->createMock(IDBConnection::class);$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
        $db->method('executeQuery')->willReturnCallback(function($sql)use($actual,$port):IResult {
            $r=$this->createMock(IResult::class);$r->method('fetch')->willReturn(['address'=>$actual,'port'=>$port]);return $r;
        });
        $config=$this->createStub(IConfig::class);$config->method('getSystemValueString')->willReturnCallback(static fn($key,$default)=>$key==='dbhost'?$configured:$default);
        return \OCA\Mail\Db\PgDirectConnection::verified($db,$config);
    }
    public function testOnlySingleMatchingTcpServerEndpointIsAdmitted():void {
        self::assertTrue($this->proof('127.0.0.1','127.0.0.1'));
        self::assertFalse($this->proof('127.0.0.1:6432','127.0.0.1'),'A transaction-pool frontend port must not be admitted.');
        self::assertFalse($this->proof('127.0.0.2:5432','127.0.0.1'),'A proxy or mismatched frontend address must not be admitted.');
        self::assertFalse($this->proof('127.0.0.1,127.0.0.2','127.0.0.1'),'Multi-host connection strings are ambiguous.');
        self::assertFalse($this->proof('/var/run/postgresql','127.0.0.1'),'Unix sockets do not establish the qualified endpoint.');
    }
}
