<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

if(getenv('MCP_FORMS_FORMAT_BOUNDARY')!=='1')throw new RuntimeException('Select an explicitly disposable Forms format cell.');
define('OC_CONSOLE',true);
require (getenv('NEXTCLOUD_ROOT')?:'/var/www/html').'/lib/base.php';
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
require_once (getenv('NEXTCLOUD_ROOT')?:'/var/www/html').'/custom_apps/forms/vendor/autoload.php';
try {
$renderer=new \OCA\Mcp\Modules\Forms\Exports\FormsExportRenderer();
$header=['Submission ID','Timestamp','Text','Formula','File'];$ids=['submission_id','timestamp','question-id-1','question-id-2','question-id-3'];
$url='https://cloud.test/f/90?view=1&file=90';
$rows=[['8','1970-01-01T01:00:00+01:00',"Unicode ñ\nline",'=1+1',['text'=>'visible.pdf ('.$url.')','url'=>$url]],
       ['9','2026-11-13T12:34:56+01:00',"Été\tfin",'  +cmd','']];
$checks=[];
foreach(['csv','ods','xlsx'] as $format) {
    $path=\OCP\Server::get(\OCP\ITempManager::class)->getTemporaryFile($format);
    try {
        $bytes=$renderer->render($format,$header,$rows,$ids);
        if(file_put_contents($path,$bytes)!==strlen($bytes))throw new RuntimeException('Could not write format fixture.');
        $reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReader(ucfirst($format));$book=$reader->load($path);$sheet=$book->getSheet(0);
        $assert=static function(bool $condition,string $message)use($format):void{if(!$condition)throw new RuntimeException($format.': '.$message);};
        $assert((string)$sheet->getCell('A1')->getValue()==='Submission ID','column header');
        $assert((string)$sheet->getCell('A2')->getValue()==='submission_id','identifier row');
        $assert((string)$sheet->getCell('A3')->getValue()==='8','submission id');
        $assert((string)$sheet->getCell('B3')->getValue()===$rows[0][1],'timestamp/timezone');
        $assert((string)$sheet->getCell('C3')->getValue()===$rows[0][2],'Unicode and newline');
        $assert((string)$sheet->getCell('C4')->getValue()===$rows[1][2],'Unicode and tab');
        $assert($sheet->getCell('D3')->getDataType()!==\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA,'formula is text');
        $assert((string)$sheet->getCell('D3')->getValue()===($format==='csv'?"'=1+1":'=1+1'),'formula literal');
        $assert((string)$sheet->getCell('D4')->getValue()===($format==='csv'?"'  +cmd":'  +cmd'),'leading whitespace formula');
        $assert((string)$sheet->getCell('E3')->getValue()===$rows[0][4]['text'],'file reference label');
        if($format!=='csv') {
            // The pinned ODS reader does not import row visibility; the unit XML contract verifies table:visibility there.
            if($format==='xlsx')$assert(!$sheet->getRowDimension(2)->getVisible(),'identifier row hidden');
            $assert($sheet->getCell('E3')->getHyperlink()->getUrl()===$url,'explicit allowed file hyperlink');
        }
        $assert($sheet->getHighestRow()===4,'exact row coverage');
        $checks[$format]=['bytes'=>strlen($bytes),'round_trip'=>true];$book->disconnectWorksheets();
    } finally {if(is_file($path))unlink($path);}
}
echo json_encode(['ok'=>true,'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
} catch(\Throwable $e) {fwrite(STDERR,json_encode(['ok'=>false,'class'=>$e::class,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR)."\n");exit(1);}
