<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\FormsExportFileReferences;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;
final class FormsExportFileReferencesTest extends TestCase {
    public function testAllIndependentlyVisibleReferencesContinueBeyondFirstPage():void {
        $calls=[];$files=[];for($id=1;$id<=151;$id++)$files[]=['file_id'=>$id];
        $result=FormsExportFileReferences::collect(function(int $offset,int $limit)use(&$calls,$files):array {
            $calls[]=[$offset,$limit];$page=array_slice($files,$offset,$limit);$more=$offset+count($page)<count($files);
            return ['files'=>$page,'_mcp_page'=>['offset'=>$offset,'hasMore'=>$more,'nextOffset'=>$more?$offset+count($page):null]];
        },static fn(array $rows):array=>array_map(static fn(array $row):array=>$row+['name'=>'file-'.$row['file_id'].'.txt'],$rows));
        self::assertSame([[0,100],[100,100]],$calls);self::assertSame(range(1,151),array_column($result,'file_id'));
        self::assertSame('file-151.txt',$result[150]['name']);
    }
    public function testRenderedExportIncludesEveryFileLabelAcrossNativePages():void {
        $refs=[];$answers=[];
        for($i=1;$i<=151;$i++) {$refs[]=['file_id'=>$i];$answers[]=['questionId'=>1,'fileId'=>$i,'text'=>'file-'.$i.'.txt'];}
        $visible=self::visibleReferences($refs);
        $snapshot=new \OCA\Mcp\Modules\Forms\Exports\FormsExportSnapshot(
            ['questions'=>[['id'=>1,'type'=>'file','text'=>'Attachments']]],false,true);
        $row=$snapshot->row(['id'=>1,'timestamp'=>1000,'answers'=>$answers],$visible);
        $csv=(new \OCA\Mcp\Modules\Forms\Exports\FormsExportRenderer())->chunk('csv',[$row]);
        $stream=fopen('php://memory','w+');fwrite($stream,$csv);rewind($stream);$cells=fgetcsv($stream,0,',','"','');fclose($stream);
        preg_match_all('/file-(\\d+)\\.txt /',$cells[2],$labels);
        self::assertSame(range(1,151),array_map('intval',$labels[1]));
    }
    private static function visibleReferences(array $refs):array {
        $files=FormsExportFileReferences::collect(static function(int $offset,int $limit)use($refs):array {
            $page=array_slice($refs,$offset,$limit);$more=$offset+count($page)<count($refs);
            return ['files'=>$page,'_mcp_page'=>['offset'=>$offset,'hasMore'=>$more,'nextOffset'=>$more?$offset+count($page):null]];
        },static fn(array $rows):array=>array_map(static fn(array $row):array=>$row+['name'=>'file-'.$row['file_id'].'.txt','url'=>'https://cloud.test/f/'.$row['file_id']],$rows));
        $out=[];foreach($files as $file)$out[$file['file_id']]=['name'=>$file['name'],'url'=>$file['url']];return $out;
    }
    public function testVisibilityFilteringDoesNotLoseNativeContinuation():void {
        $result=FormsExportFileReferences::collect(static fn(int $offset,int $limit):array=>[
            'files'=>[['file_id'=>$offset+1]],'_mcp_page'=>['offset'=>$offset,'hasMore'=>$offset===0,'nextOffset'=>$offset===0?1:null]],
            static fn(array $rows):array=>$rows[0]['file_id']===1?[]:[['file_id'=>2,'name'=>'visible']]);
        self::assertSame([['file_id'=>2,'name'=>'visible']],$result);
    }
    public function testMalformedNativeContinuationRefusesInsteadOfPublishingTruncatedOutput():void {
        $this->expectException(ToolFailure::class);
        FormsExportFileReferences::collect(static fn():array=>['files'=>[['file_id'=>1]],'_mcp_page'=>['offset'=>0,'hasMore'=>true,'nextOffset'=>0]],static fn(array $rows):array=>$rows);
    }
    public function testLegacyBoundedReferenceEnvelopeRemainsSupported():void {
        self::assertSame([['file_id'=>9]],FormsExportFileReferences::collect(static fn():array=>['files'=>[['file_id'=>9]]],static fn(array $rows):array=>$rows));
    }
    public function testSharedStepDeadlineStopsBeforeReadingTheNextNativePage():void {
        $reads=0;$expired=false;
        try {
            FormsExportFileReferences::collect(function()use(&$reads):array {$reads++;return ['files'=>[['file_id'=>1]],'_mcp_page'=>['offset'=>0,'hasMore'=>true,'nextOffset'=>1]];},
                function(array $rows)use(&$expired):array{$expired=true;return $rows;},
                static function()use(&$expired):void{if($expired)throw new \OCA\Mcp\Modules\Forms\Exports\FormsExportBudgetExceeded('bounded');});
            self::fail('Expired enrichment continued.');
        } catch(\OCA\Mcp\Modules\Forms\Exports\FormsExportBudgetExceeded) {self::assertSame(1,$reads);}
    }
}
