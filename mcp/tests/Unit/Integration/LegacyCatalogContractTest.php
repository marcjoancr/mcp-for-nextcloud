<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;

use OCA\Mcp\Tests\Integration\Compat\LegacyCatalogContract;
use PHPUnit\Framework\TestCase;

/** A legacy comparison cannot hide changed grants when a new capability is added. */
final class LegacyCatalogContractTest extends TestCase {
    public function testOnlyTheReviewedCatalogAdditionsAreAccepted(): void {
        $baseline = ['files' => ['read', 'edit'], 'deck' => ['read', 'edit'], 'talk' => ['read', 'reply'], 'calendar' => ['read', 'create'], 'logs' => ['read']];
        $expected = $baseline;
        $expected['files'] = [...$expected['files'], 'description_read', 'description_edit', 'tags_read', 'tags_create', 'tags_edit', 'upload_link'];
        $expected['deck'] = [...$expected['deck'], 'read_comments', 'comment', 'search'];
        $expected['calendar'] = [...$expected['calendar'], 'availability'];
        $expected['talk'] = [...$expected['talk'], 'publish_summary', 'search'];
        self::assertSame($baseline, LegacyCatalogContract::baseline($baseline, [...$expected, 'mail' => \OCA\Mcp\Access\Grants\GrantPolicy::CATALOG['mail'], 'forms' => ['read', 'read_responses', 'summarize_responses', 'export_responses']]));
        foreach ([$baseline, ['files' => ['read'], 'logs' => ['read'], 'mail' => ['read']],
            [...$baseline, 'mail' => ['read', 'write']], [...$baseline, 'unknown' => ['read']],
            ['mail' => ['read']]] as $current) {
            try { LegacyCatalogContract::baseline($baseline, $current); self::fail('Changed catalog accepted'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        $this->expectException(\UnexpectedValueException::class);
        LegacyCatalogContract::baseline([...$baseline, 'mail' => ['read']], [...$baseline, 'mail' => ['read']]);
    }

    public function testOnlyExplicitlyDeniedNewOperationsAreRemovedAndAllOtherValuesSurvive(): void {
        $legacy = ['1' => ['globalEnabled' => true, 'users' => ['alice' => ['grants' => ['files' => ['read' => false]]]],
            'matrix' => ['alice' => ['eligible' => true, 'connected' => false, 'grants' => ['files' => ['read' => false], 'deck' => ['read' => true], 'talk' => ['read' => true], 'calendar' => ['read' => true]]]]]];
        $branch = $legacy;
        $files = ['description_read', 'description_edit', 'tags_read', 'tags_create', 'tags_edit', 'upload_link'];
        $branch['1']['matrix']['alice']['grants']['files'] += array_fill_keys($files, false);
        $branch['1']['matrix']['alice']['grants']['mail'] = array_fill_keys(\OCA\Mcp\Access\Grants\GrantPolicy::CATALOG['mail'],false);
        $branch['1']['matrix']['alice']['grants']['forms'] = ['read' => false, 'read_responses' => false, 'summarize_responses' => false, 'export_responses' => false];
        $branch['1']['matrix']['alice']['grants']['talk'] += ['publish_summary' => false, 'search' => false];
        $branch['1']['matrix']['alice']['grants']['deck'] += ['read_comments' => false, 'comment' => false, 'search' => false];
        $branch['1']['matrix']['alice']['grants']['calendar'] += ['availability' => false];
        self::assertSame($legacy, LegacyCatalogContract::answers($branch));
        foreach ([['forms', 'summarize_responses'], ['forms', 'export_responses'], ['deck', 'search'], ['calendar', 'availability']] as [$module, $operation]) {
            $unsafe = $branch;
            $unsafe['1']['matrix']['alice']['grants'][$module][$operation] = true;
            try { LegacyCatalogContract::answers($unsafe); self::fail('Unsafe new capability default accepted'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        foreach ($files as $operation) {
            foreach ([true, 0, null] as $invalid) {
                $unsafe = $branch;
                if ($invalid === null) {
                    unset($unsafe['1']['matrix']['alice']['grants']['files'][$operation]);
                } else {
                    $unsafe['1']['matrix']['alice']['grants']['files'][$operation] = $invalid;
                }
                try { LegacyCatalogContract::answers($unsafe); self::fail("Unsafe Files $operation accepted"); }
                catch (\UnexpectedValueException) { self::assertTrue(true); }
            }
        }
        self::assertArrayHasKey('mail', $branch['1']['matrix']['alice']['grants'], 'The input must not be modified');
        $branch['1']['matrix']['alice']['grants']['files']['read'] = true;
        self::assertNotSame($legacy, LegacyCatalogContract::answers($branch), 'Legacy changes must remain visible');
        foreach ([['read' => true], ['read' => 0], ['read' => false, 'write' => false], [], null] as $invalid) {
            $branch['1']['matrix']['alice']['grants']['mail'] = $invalid;
            try { LegacyCatalogContract::answers($branch); self::fail('Unsafe Mail default accepted'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        foreach(\OCA\Mcp\Access\Grants\GrantPolicy::CATALOG['mail']as$operation){
            foreach([true,0,null]as$invalid){
                $bad=$branch;$bad['1']['matrix']['alice']['grants']['mail']=array_fill_keys(\OCA\Mcp\Access\Grants\GrantPolicy::CATALOG['mail'],false);
                if($invalid===null)unset($bad['1']['matrix']['alice']['grants']['mail'][$operation]);else $bad['1']['matrix']['alice']['grants']['mail'][$operation]=$invalid;
                try{LegacyCatalogContract::answers($bad);self::fail('New Mail capability must be explicitly denied');}catch(\UnexpectedValueException){self::assertTrue(true);}
            }
        }
        unset($branch['1']['matrix']['alice']['grants']['mail']);
        $this->expectException(\UnexpectedValueException::class);
        LegacyCatalogContract::answers($branch);
    }
    public function testOnlyValidatedUniqueStableActorWitnessesAreSeparatedFromExactLegacyBytes():void {
        $raw=['users'=>[],'service'=>null,'tokens'=>['access_hash'=>'OLD_TOKEN_BYTES']];$actors=[];
        foreach(\OCA\Mcp\Tests\Integration\Compat\SnapshotProbe::uids()as$uid){$actors[$uid]=hash('sha256',$uid);$raw['users'][$uid]=['eligible'=>['value'=>'1','type'=>'MIXED','lazy'=>false,'flags'=>0]];}
        $current=$raw;
        foreach($actors as$uid=>$value)$current['users'][$uid]['operation_actor_incarnation']=['value'=>$value,'type'=>'STRING','lazy'=>false,'flags'=>5];
        self::assertSame($raw,LegacyCatalogContract::legacyPreferences($current,$actors));
        self::assertArrayHasKey('operation_actor_incarnation',$current['users']['mcp-it-snap-0']);
        foreach(['flags','type','lazy','value','missing','duplicate','rotation']as$bad){
            $broken=$current;$expected=$actors;$uid='mcp-it-snap-0';
            switch($bad){
                case 'flags':$broken['users'][$uid]['operation_actor_incarnation']['flags']=0;break;
                case 'type':$broken['users'][$uid]['operation_actor_incarnation']['type']='MIXED';break;
                case 'lazy':$broken['users'][$uid]['operation_actor_incarnation']['lazy']=true;break;
                case 'value':$broken['users'][$uid]['operation_actor_incarnation']['value']='invalid';break;
                case 'missing':unset($broken['users'][$uid]['operation_actor_incarnation']);break;
                case 'duplicate':$expected[$uid]=$expected['mcp-it-snap-1'];$broken['users'][$uid]['operation_actor_incarnation']['value']=$expected[$uid];break;
                case 'rotation':$broken['users'][$uid]['operation_actor_incarnation']['value']=hash('sha256','replacement');break;
            }
            try{LegacyCatalogContract::legacyPreferences($broken,$expected);self::fail('Invalid lifecycle witness accepted');}catch(\UnexpectedValueException){self::assertTrue(true);}
        }
        foreach(['old-value','extra-key','token-bytes']as$change){
            $changed=$current;
            if($change==='old-value')$changed['users']['mcp-it-snap-0']['eligible']['value']='0';
            elseif($change==='extra-key')$changed['users']['mcp-it-snap-0']['unexpected']='must remain visible';
            else $changed['tokens']['access_hash']='CHANGED_TOKEN_BYTES';
            self::assertNotSame($raw,LegacyCatalogContract::legacyPreferences($changed,$actors),'Every non-witness change must remain visible');
        }
    }

}
