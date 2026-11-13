<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\{FormsExportSnapshot,FormsExportRenderer};
use PHPUnit\Framework\TestCase;
final class FormsCompleteExportTest extends TestCase {
    private function metadata():array {return ['form'=>['id'=>4,'isAnonymous'=>false],'timeZone'=>'Europe/Madrid','questions'=>[
        ['id'=>1,'text'=>'Text','type'=>'short'],
        ['id'=>2,'text'=>'Grid','type'=>'grid','extraSettings'=>['questionType'=>'number'],'options'=>[['id'=>21,'text'=>'Row','optionType'=>'row'],['id'=>22,'text'=>'Column','optionType'=>'column']]],
        ['id'=>3,'text'=>'Ranking','type'=>'ranking','options'=>[['id'=>31,'text'=>'First'],['id'=>32,'text'=>'Second']]],
        ['id'=>4,'text'=>'File','type'=>'file']]];}
    public function testGridRankingScaleAndDeletedQuestionResultsHaveNativeMeaning():void {
        $s=new FormsExportSnapshot($this->metadata(),false,false);
        $row=$s->row(['id'=>8,'timestamp'=>0,'userId'=>'alice','answers'=>[
            ['questionId'=>1,'text'=>"Unicode ñ\n=SUM(1)"],['questionId'=>2,'text'=>'{"21":{"22":"0"}}'],['questionId'=>3,'text'=>'[32,31]'],['questionId'=>999,'text'=>'deleted'],['questionId'=>4,'fileId'=>90,'text'=>'secretfile']]]);
        self::assertSame(['Submission ID','Timestamp','Text','Grid (Row - Column)','Ranking (First)','Ranking (Second)','File'],array_column($s->columns(),'title'));
        self::assertSame(['8','1970-01-01T01:00:00+01:00',"Unicode ñ\n=SUM(1)",'0','2','1',''],$row);
        self::assertStringNotContainsString('alice',json_encode($row));self::assertStringNotContainsString('deleted',json_encode($row));
    }
    public function testAnonymousHistoricalIdentityStaysEmptyWithIdentityGrant():void {
        $s=new FormsExportSnapshot($this->metadata(),true,false);
        $row=$s->row(['id'=>8,'timestamp'=>0,'userId'=>'anon-user-abc','userDisplayName'=>'Private','answers'=>[]]);
        self::assertSame('',$row[1]);self::assertSame('',$row[2]);
        $m=$this->metadata();$m['form']['isAnonymous']=true;
        $row=(new FormsExportSnapshot($m,true,false))->row(['id'=>8,'timestamp'=>0,'userId'=>'alice','userDisplayName'=>'Private','answers'=>[]]);
        self::assertSame('',$row[1]);self::assertSame('',$row[2]);
    }
    public function testFileLinksRequireIndependentlyVisibleReferences():void {
        $s=new FormsExportSnapshot($this->metadata(),false,true);
        $row=$s->row(['id'=>8,'timestamp'=>0,'answers'=>[['questionId'=>4,'fileId'=>90,'text'=>'hidden']]],[]);
        self::assertSame('',$row[6]);
        $row=$s->row(['id'=>8,'timestamp'=>0,'answers'=>[['questionId'=>4,'fileId'=>90,'text'=>'hidden']]],['90'=>['name'=>'visible','url'=>'https://cloud.test/f/90']]);
        self::assertSame(['text'=>'visible (https://cloud.test/f/90)','url'=>'https://cloud.test/f/90'],$row[6]);
    }
    public function testSummaryCountsSelectionsWithoutSplittingLabelsAndReportsAttachmentCounts():void {
        $meta=['form'=>['isAnonymous'=>false],'questions'=>[['id'=>1,'text'=>'Choice','type'=>'multiple'],['id'=>2,'text'=>'Files','type'=>'file']]];
        $s=new FormsExportSnapshot($meta,false,false);
        $cells=$s->summaryCells(['id'=>1,'timestamp'=>0,'answers'=>[['questionId'=>1,'text'=>'literal; semicolon'],['questionId'=>1,'text'=>'second'],['questionId'=>2,'text'=>'private','fileId'=>90]]]);
        self::assertSame(['literal; semicolon','second'],$cells[2]);self::assertSame(['1'],$cells[3]);self::assertStringNotContainsString('private',json_encode($cells));
    }
    public function testSpreadsheetIdentifiersAreHiddenAndFileCellsHaveExplicitHyperlinks():void {
        $r=new FormsExportRenderer();
        foreach(['xlsx'=>'xl/worksheets/sheet1.xml','ods'=>'content.xml'] as $format=>$entry) {
            $file=tempnam(sys_get_temp_dir(),'forms-links-');
            try {
                file_put_contents($file,$r->render($format,['File'],[[['text'=>'visible','url'=>'https://cloud.test/f/90']]],['question-id-4']));
                $zip=new \ZipArchive();self::assertTrue($zip->open($file));$xml=$zip->getFromName($entry);
                self::assertIsString($xml);$doc=new \DOMDocument();self::assertTrue($doc->loadXML($xml));
                $xp=new \DOMXPath($doc);
                if($format==='xlsx') {
                    $xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    self::assertSame('1',$xp->evaluate('string(//s:row[@r="2"]/@hidden)'));
                    self::assertSame('A3',$xp->evaluate('string(//s:hyperlink/@ref)'));
                    self::assertStringContainsString('https://cloud.test/f/90',$zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels'));
                } else {
                    $xp->registerNamespace('t','urn:oasis:names:tc:opendocument:xmlns:table:1.0');
                    $xp->registerNamespace('x','http://www.w3.org/1999/xlink');
                    self::assertSame('collapse',$xp->evaluate('string((//t:table-row)[2]/@t:visibility)'));
                    self::assertSame('https://cloud.test/f/90',$xp->evaluate('string(//*[@x:href]/@x:href)'));
                }
                $zip->close();
            } finally {unlink($file);}
        }
    }
    public function testFinalOutputQuotaIncludesContainerAndCsvBom():void {
        $this->expectException(\RuntimeException::class);
        (new FormsExportRenderer())->assemble('csv',[str_repeat('a',26214400)]);
    }
    public function testFormulaLikeValuesAreTextInAllFormats():void {
        $r=new FormsExportRenderer();$rows=[['=1+1',"+cmd","\t@cmd","é\nline"]];
        $csv=$r->render('csv',['a','b','c','d'],$rows);$f=fopen('php://memory','w+');fwrite($f,$csv);rewind($f);fgetcsv($f,0,',','"','');$read=fgetcsv($f,0,',','"','');fclose($f);
        self::assertSame(["'=1+1","'+cmd","'\t@cmd","é\nline"],$read);
        foreach(['xlsx'=>'xl/worksheets/sheet1.xml','ods'=>'content.xml'] as $format=>$entry) {
            $bytes=$r->render($format,['a','b','c','d'],$rows);
            $zip=new \ZipArchive();$file=tempnam(sys_get_temp_dir(),'forms-export-unit-');file_put_contents($file,$bytes);
            self::assertTrue($zip->open($file));$xml=$zip->getFromName($entry);$zip->close();unlink($file);
            self::assertIsString($xml);$doc=new \DOMDocument();self::assertTrue($doc->loadXML($xml));
            self::assertStringContainsString('=1+1',$doc->textContent);self::assertStringNotContainsString('<f>',$xml);self::assertStringNotContainsString('table:formula',$xml);
        }
    }
}
