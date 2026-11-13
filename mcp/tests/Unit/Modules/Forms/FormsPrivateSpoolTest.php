<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Db\FormsSpoolMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version002800Date20261113110000;
use OCA\Mcp\Modules\Forms\Exports\FormsPrivateSpool;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
final class FormsPrivateSpoolTest extends TestCase {
    private array $files=[];private bool $created=false;private bool $loseWriteAck=false;private bool $locked=false;private int $now=1000;private FormsPrivateSpool $spool;private FormsSpoolMapper $budget;private SqliteDatabase $sql;
    private string $job;
    protected function setUp():void {
        $this->job=hash('sha256','private-spool');$unused=$this->createMock(\OCP\IDBConnection::class);
        $this->sql=new SqliteDatabase($this,[new Version001100Date20261015000000($unused),new Version002800Date20261113110000()]);
        $this->sql->dbal->executeStatement('INSERT INTO mcp_policy_state (id,revision,lock_seq,auth_epoch) VALUES (1,0,0,0)');
        $db=$this->sql->connection();$clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(fn():int=>$this->now);
        $this->budget=new FormsSpoolMapper($db,$clock,new PolicyStateMapper($db));
        $folder=$this->createMock(ISimpleFolder::class);
        $folder->method('fileExists')->willReturnCallback(fn(string $name):bool=>array_key_exists($name,$this->files));
        $file=function(string $name):ISimpleFile {
            $f=$this->createMock(ISimpleFile::class);
            $f->method('getSize')->willReturnCallback(fn():int=>strlen($this->files[$name]));
            $f->method('getContent')->willReturnCallback(function()use($name):string{self::assertTrue($this->locked);return $this->files[$name];});
            $f->method('delete')->willReturnCallback(function()use($name):void{self::assertTrue($this->locked);unset($this->files[$name]);});
            return $f;
        };
        $folder->method('getFile')->willReturnCallback(function(string $name)use($file):ISimpleFile {if(!array_key_exists($name,$this->files))throw new NotFoundException();return $file($name);});
        $folder->method('newFile')->willReturnCallback(function(string $name,string $content)use($file):ISimpleFile {
            self::assertTrue($this->locked);self::assertFalse($this->sql->dbal->isTransactionActive());self::assertGreaterThanOrEqual(strlen($content),(int)$this->sql->dbal->fetchOne('SELECT used_bytes FROM mcp_forms_spool'));$this->files[$name]=$content;if($this->loseWriteAck)throw new \RuntimeException('lost physical write acknowledgement');return $file($name);
        });
        $folder->expects(self::never())->method('delete');$folder->expects(self::never())->method('getDirectoryListing');
        $data=$this->createMock(IAppData::class);
        $data->method('getFolder')->willReturnCallback(function(string $name)use($folder):ISimpleFolder {self::assertSame('forms-results-v2-'.$this->job,$name);if(!$this->created)throw new NotFoundException();return $folder;});
        $data->method('newFolder')->willReturnCallback(function(string $name)use($folder):ISimpleFolder {self::assertTrue($this->locked);self::assertSame('forms-results-v2-'.$this->job,$name);$this->created=true;return $folder;});
        $crypto=$this->createMock(ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(static fn(string $value):string=>bin2hex($value).str_repeat('a',196));
        $crypto->method('decrypt')->willReturnCallback(static fn(string $value):string=>hex2bin(substr($value,0,-196)));
        $locks=$this->createMock(ILockingProvider::class);
        $locks->method('acquireLock')->willReturnCallback(function(string $key,int $type):void {self::assertSame(hash('sha256','mcp/forms-spool/v2:'.$this->job),$key);self::assertSame(ILockingProvider::LOCK_EXCLUSIVE,$type);self::assertFalse($this->locked);$this->locked=true;});
        $locks->method('releaseLock')->willReturnCallback(function(string $key,int $type):void {self::assertTrue($this->locked);$this->locked=false;});
        $this->spool=new FormsPrivateSpool($data,$crypto,$this->budget,$locks);
    }
    public function testUnadmittedArtifactCreatesNoFolderOrBytes():void {
        try {$this->spool->put($this->job,'unapproved');self::fail('Unadmitted artifact written');}catch(\RuntimeException $error) {self::assertStringContainsString('reservation',$error->getMessage());}
        self::assertFalse($this->created);self::assertSame([],$this->files);self::assertFalse($this->locked);
    }
    public function testNativeHexEncryptionOverheadDoesNotRejectTheAllowed25MiB():void {
        $this->spool->reserve($this->job,'alice');$bytes=str_repeat('x',26214400);$part=$this->spool->put($this->job,$bytes);
        self::assertSame(52428996,strlen($this->files[$part['key']]));
        self::assertSame($bytes,$this->spool->get($this->job,$part));$this->spool->seal($this->job);
        self::assertSame(52428996,(int)$this->sql->dbal->fetchOne('SELECT reserved_bytes FROM mcp_forms_spool'));
    }
    public function testCiphertextMustMatchItsDurableChargeBeforeDecryption():void {
        $this->spool->reserve($this->job,'alice');$part=$this->spool->put($this->job,'verified');
        $this->files[$part['key']].='unexpected';
        $this->expectExceptionMessage('Forms ciphertext reservation changed.');$this->spool->get($this->job,$part);
    }
    public function testAmbiguousPhysicalWriteRetainsBytesAndQuotaAfterExpiry():void {
        $this->spool->reserve($this->job,'alice');$this->loseWriteAck=true;
        try {$this->spool->put($this->job,'possibly still writing');self::fail('Write failure hidden');}catch(\RuntimeException) {}
        self::assertCount(1,$this->files);$this->now+=FormsSpoolMapper::RETENTION_SECONDS;$this->spool->cleanup();
        self::assertCount(1,$this->files);self::assertSame(FormsSpoolMapper::JOB_BYTES,(int)$this->sql->dbal->fetchOne('SELECT reserved_bytes FROM mcp_forms_spool'));
        self::assertFalse($this->locked);
    }
    public function testCleanupDeletesAtMost100ChargedFilesAndNeverListsOrRecurses():void {
        $this->spool->reserve($this->job,'alice');
        for($i=0;$i<101;$i++)$this->spool->put($this->job,'part-'.$i);
        $this->now+=FormsSpoolMapper::RETENTION_SECONDS;$this->spool->cleanup();self::assertCount(1,$this->files);
        self::assertSame('cleaning',$this->sql->dbal->fetchOne('SELECT state FROM mcp_forms_spool'));
        try {$this->spool->put($this->job,'late');self::fail('Late artifact resurrected');}catch(\RuntimeException) {}
        $this->spool->cleanup();self::assertCount(0,$this->files);
        self::assertSame('expired',$this->sql->dbal->fetchOne('SELECT state FROM mcp_forms_spool'));
        self::assertFalse($this->locked);
    }
}
