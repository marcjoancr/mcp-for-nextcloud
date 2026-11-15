<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
spl_autoload_register(static function(string $class):void {
    $file=null;
    if(str_starts_with($class,'OCP\\'))$file=dirname(__DIR__).'/references/lib__public__'.str_replace('\\','__',substr($class,4)).'.php';
    elseif(in_array($class,['OC\\AllConfig','OC\\DateTimeZone'],true))$file=dirname(__DIR__).'/references/lib__private__'.substr($class,3).'.php';
    if($file!==null&&is_file($file))require_once$file;
});
require_once dirname(__DIR__).'/src/TransferTimeZoneSession.php';
require_once dirname(__DIR__).'/src/TransferTimeZoneConfig.php';
final class TimeZoneTest extends TestCase {
    public function testNativeAlgorithmReadsOriginalPreferenceIndependentOfAmbientBOrNullWorker():void {
        foreach (['Pacific/Honolulu',null] as $ambient) {
            $ambientSession=$this->createMock(\OCP\ISession::class);$ambientSession->expects(self::never())->method('get');
            $calls=0;$frame=static function()use(&$calls):void {++$calls;};
            $session=new \OCA\Forms\Mcp\Service\TransferTimeZoneSession('synthetic-original',$frame);
            $config=new \OCA\Forms\Mcp\Service\TransferTimeZoneConfig('synthetic-original','Europe/Madrid','UTC');
            $logger=$this->createMock(\Psr\Log\LoggerInterface::class);$logger->expects(self::never())->method('debug');
            $native=new \OC\DateTimeZone($config,$session,$logger);
            self::assertSame('Europe/Madrid',$native->getTimeZone()->getName());self::assertGreaterThan(0,$calls);
        }
    }
    public function testNativeAuthenticatedFallbackUsesSystemZoneAndNeverAmbientCookieOffset():void {
        $session=new \OCA\Forms\Mcp\Service\TransferTimeZoneSession('synthetic-original',static function():void {});
        $native=new \OC\DateTimeZone(new \OCA\Forms\Mcp\Service\TransferTimeZoneConfig('synthetic-original','','Europe/Paris'),$session,new \Psr\Log\NullLogger());
        self::assertSame('Europe/Paris',$native->getTimeZone()->getName());self::assertFalse($session->exists('timezone'));
    }
    public function testInvalidPreferenceRefusesBeforeNativeLoggerCanRun():void {
        $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Transfer timezone preference is unqualified');
        new \OCA\Forms\Mcp\Service\TransferTimeZoneConfig('synthetic-original','not/a-zone','UTC');
    }
    public function testFrameRevocationRefusesPrivateSessionRead():void {
        $session=new \OCA\Forms\Mcp\Service\TransferTimeZoneSession('synthetic-original',static function():void {throw new RuntimeException('synthetic revoked frame');});
        $this->expectException(RuntimeException::class);$this->expectExceptionMessage('synthetic revoked frame');$session->get('user_id');
    }
    public function testPrivateSessionCannotCreateCookieOrWrite():void {
        $session=new \OCA\Forms\Mcp\Service\TransferTimeZoneSession('synthetic-original',static function():void {});
        $this->expectException(LogicException::class);$this->expectExceptionMessage('Transfer timezone session writes are forbidden');$session->set('user_id','other');
    }
}
