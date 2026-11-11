<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;
use OCA\Mcp\Tests\Integration\Mail\MailDeliveryActor;
use PHPUnit\Framework\TestCase;
final class DeliveryPeerTest extends TestCase {
    private function start(string $mode):array {
        $peer=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Integration/Mail/delivery-imap-server.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($peer);stream_set_timeout($pipes[1],5);
        $ready=json_decode((string)fgets($pipes[1]),true,16,JSON_THROW_ON_ERROR);
        return [$peer,$pipes,$ready['port']];
    }
    private function connect(int $port) {
        $client=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,2);self::assertIsResource($client,'Synthetic listener must remain alive while its control pipe is held');stream_set_timeout($client,3);
        self::assertSame("* OK synthetic append fixture\r\n",fgets($client));return $client;
    }
    private function command($client,string $tag,string $command):string {
        fwrite($client,$tag.' '.$command."\r\n");$out='';
        while(($line=fgets($client))!==false){$out.=$line;if(str_starts_with($line,$tag.' '))return $out;}
        self::fail('Synthetic protocol response incomplete');
    }
    private function finish($peer,array $pipes):array {
        require_once dirname(__DIR__,2).'/Integration/Mail/MailDeliveryActor.php';
        return (new \ReflectionMethod(MailDeliveryActor::class,'finishCopyPeer'))->invoke(null,$peer,$pipes);
    }
    private function cleanup($peer,array $pipes):void {
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
        if(is_resource($peer)){proc_terminate($peer,9);proc_close($peer);}
    }
    public function testHeldControlSurvivesLongGapAndEofReturnsCounters():void {
        [$peer,$pipes,$port]=$this->start('uid_after_literal');$client=null;
        try{
            $client=$this->connect($port);$this->command($client,'a','LOGIN unused unused');$this->command($client,'b','STATUS Sent (UIDVALIDITY)');$this->command($client,'c','LOGOUT');fclose($client);$client=null;
            usleep(3500000);
            $client=$this->connect($port);$this->command($client,'a','LOGIN unused unused');$this->command($client,'b','STATUS Sent (UIDVALIDITY)');$this->command($client,'c','LOGOUT');fclose($client);$client=null;
            $start=hrtime(true);$counts=$this->finish($peer,$pipes);self::assertLessThan(3,(hrtime(true)-$start)/1e9);
            self::assertSame(['appends'=>0,'connections'=>2,'searches'=>0,'fetches'=>0],$counts);
            self::assertFalse(is_resource($peer));foreach($pipes as $pipe)self::assertFalse(is_resource($pipe));
        }finally{if(is_resource($client))fclose($client);$this->cleanup($peer,$pipes);}
    }
    public function testLostAcknowledgementStillAcceptsExactlyOneLiteralAndNoSearch():void {
        [$peer,$pipes,$port]=$this->start('lost_ack');$client=null;
        try{
            $client=$this->connect($port);$caps=$this->command($client,'a','CAPABILITY');self::assertStringContainsString(' UIDPLUS BINARY',$caps);$this->command($client,'b','LOGIN unused unused');
            fwrite($client,"c APPEND Sent {3}\r\n");self::assertSame("+ literal ready\r\n",fgets($client));fwrite($client,"abc\r\n");self::assertFalse(fgets($client));fclose($client);$client=null;
            self::assertSame(['appends'=>1,'connections'=>1,'searches'=>0,'fetches'=>0],$this->finish($peer,$pipes));
        }finally{if(is_resource($client))fclose($client);$this->cleanup($peer,$pipes);}
    }
    public function testUnsupportedUidplusRemainsAbsentAndEofNeedsNoConnection():void {
        [$peer,$pipes,$port]=$this->start('no_uidplus');$client=null;
        try{
            $client=$this->connect($port);$caps=$this->command($client,'a','CAPABILITY');self::assertStringNotContainsString('UIDPLUS',$caps);self::assertStringContainsString('AUTH=PLAIN SASL-IR BINARY',$caps);$this->command($client,'b','LOGOUT');fclose($client);$client=null;
            self::assertSame(['appends'=>0,'connections'=>1,'searches'=>0,'fetches'=>0],$this->finish($peer,$pipes));
        }finally{if(is_resource($client))fclose($client);$this->cleanup($peer,$pipes);}
        [$peer,$pipes]=$this->start('lost_ack');
        try{self::assertSame(['appends'=>0,'connections'=>0,'searches'=>0,'fetches'=>0],$this->finish($peer,$pipes));}finally{$this->cleanup($peer,$pipes);}
    }
    public function testUnresponsivePeerIsTerminatedAndReapedWithoutReportingSuccess():void {
        $peer=proc_open([PHP_BINARY,'-r','sleep(30);'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($peer);$start=hrtime(true);
        try{
            try{$this->finish($peer,$pipes);self::fail('Forced peer exit reported as successful evidence');}
            catch(\RuntimeException $e){self::assertSame('Synthetic APPEND peer did not complete normally.',$e->getMessage());}
            self::assertLessThan(8,(hrtime(true)-$start)/1e9);self::assertFalse(is_resource($peer));foreach($pipes as $pipe)self::assertFalse(is_resource($pipe));
        }finally{$this->cleanup($peer,$pipes);}
    }

}
