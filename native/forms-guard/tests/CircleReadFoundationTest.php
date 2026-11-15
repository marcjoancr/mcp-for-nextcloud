<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\{CircleReadData,CircleReadConfig,CircleExistingIdentity};
final class CircleReadFoundationTest extends TestCase {
    protected function setUp(): void {
        foreach (['CircleReadData','CircleReadConfig','CircleExistingIdentity'] as $class) {
            $file=dirname(__DIR__).'/src/Service/'.$class.'.php';
            self::assertFileExists($file, 'Missing inert Circle read foundation');require_once $file;
        }
    }
    public function testMissingAndDuplicateIdentityDoNotBecomePermissionEvidence(): void {
        foreach ([[],[$this->identity(),$this->identity()]] as $rows) {
            try {CircleExistingIdentity::select($rows,'synthetic-user');self::fail('Ambiguous identity accepted');}
            catch (RuntimeException $e) {self::assertStringContainsString('identity',$e->getMessage());}
        }
    }
    public function testCanonicalIdentityReturnsOnlyBoundDataAndKeepsInputUnchanged(): void {
        $row=$this->identity();$before=$row;$result=CircleExistingIdentity::select([$row],'synthetic-user');
        self::assertSame($before,$row);self::assertSame($row['unique_id'],$result['circle_id']);
        self::assertSame('synthetic-user',$result['uid']);self::assertArrayNotHasKey('permissions',$result);
    }
    public function testIdentityCannotAdoptAnotherPrincipalOrMalformedNativeRow(): void {
        foreach (['user_id'=>'other','single_id'=>str_repeat('b',31),'source'=>2,'level'=>8,'instance'=>'foreign.invalid','status'=>'Invited'] as $key=>$value) {
            $row=$this->identity();$row[$key]=$value;
            try {CircleExistingIdentity::select([$row],'synthetic-user');self::fail('Foreign identity accepted');}
            catch (RuntimeException $e) {self::assertStringContainsString('identity',$e->getMessage());}
        }
    }
    public function testLegacySettingsCauseWholePageRefusalBeforeAnyHydration(): void {
        $rows=[['unique_id'=>'safe','settings'=>'{}'],['nested'=>['g_settings'=>'{"password_single":"legacy-value"}']]];
        $this->expectException(RuntimeException::class);$this->expectExceptionMessage('migration');CircleReadData::page($rows);
    }
    public function testMalformedSettingsAndObjectsCannotDisappearFromPage(): void {
        foreach ([['settings'=>'{'],['settings'=>'null'],['settings'=>'{"password_single":1}'],['nested'=>new stdClass()]] as $bad) {
            try {CircleReadData::page([['settings'=>'{}'],$bad]);self::fail('Invalid row omitted or accepted');}
            catch (RuntimeException $e) {self::assertNotSame('',$e->getMessage());}
        }
    }
    public function testClosedPageIsDetachedAndDoesNotRunSerializationHooks(): void {
        $value=new class implements JsonSerializable {public function jsonSerialize(): mixed {throw new LogicException('Must not run');}};
        try {CircleReadData::page([['value'=>$value]]);self::fail('Object accepted');}
        catch (RuntimeException $e) {self::assertStringContainsString('object',$e->getMessage());}
        $rows=[['settings'=>'{"password_single":"'.str_repeat('a',64).'"}','nested'=>['count'=>3]]];
        self::assertSame($rows,CircleReadData::page($rows));
    }
    public function testRowsDepthAndCumulativeBytesAreBounded(): void {
        $deep=[];for($i=0;$i<35;$i++){$deep=['child'=>$deep];}
        foreach ([array_fill(0,101,[]),[['a'=>str_repeat('x',600000)],['b'=>str_repeat('x',600000)]],[['deep'=>$deep]]] as $rows) {
            try {CircleReadData::page($rows);self::fail('Unbounded page accepted');}
            catch (RuntimeException $e) {self::assertStringContainsString('budget',$e->getMessage());}
        }
    }
    public function testExistingConfigNeedsNoRepairAndRetainsNativePrecedence(): void {
        $app=['loopback_tmp_scheme'=>'https'];$system=['overwrite.cli.url'=>'https://local.invalid','trusted_domains'=>['LOCAL.INVALID']];$before=[$app,$system];
        $result=CircleReadConfig::resolve($app,$system);self::assertSame('local.invalid',$result['local_instance']);self::assertSame(['local.invalid'],$result['trusted_domains']);self::assertSame($before,[$app,$system]);
        $result=CircleReadConfig::resolve(['loopback_tmp_id'=>'temporary.invalid','loopback_cloud_id'=>'explicit.invalid'],[]);
        self::assertSame('temporary.invalid',$result['local_instance']);
    }
    public function testMissingAndRepairRequiredConfigRefuseWithoutSubstitutingDefaults(): void {
        $cases=[[[],[]],[[],['overwrite.cli.url'=>'https://local.invalid']],[['loopback_tmp_scheme'=>'http'],['overwrite.cli.url'=>'https://local.invalid']],[['loopback_tmp_scheme'=>'https'],['overwrite.cli.url'=>'https://local.invalid/subpath']],[['loopback_cloud_id'=>'local.invalid','local_cloud_id'=>'legacy.invalid'],[]]];
        foreach($cases as [$app,$system]) {try {CircleReadConfig::resolve($app,$system);self::fail('Config requiring native write accepted');}catch(RuntimeException $e){self::assertStringContainsString('configuration',$e->getMessage());}}
    }
    public function testMalformedConfigCannotEnterNativeGetters(): void {
        foreach([['loopback_cloud_id'=>new stdClass()],['loopback_cloud_id'=>'https://not-a-cloud-id.invalid'],['loopback_cloud_id'=>"local.invalid\n"]] as$app){try{CircleReadConfig::resolve($app,[]);self::fail('Malformed config accepted');}catch(RuntimeException$e){self::assertStringContainsString('configuration',$e->getMessage());}}
    }
    private function identity(): array {return ['unique_id'=>str_repeat('a',31),'creation'=>'2026-11-15 10:00:00','config'=>1,'source'=>1,'settings'=>'{}','member_id'=>str_repeat('a',31),'circle_id'=>str_repeat('a',31),'single_id'=>str_repeat('a',31),'user_id'=>'synthetic-user','user_type'=>1,'level'=>9,'instance'=>'','status'=>'Member'];}
}
