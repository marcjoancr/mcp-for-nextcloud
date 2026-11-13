<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
/** Formula-safe CSV and explicit text cells in standard ODS/XLSX, without a spreadsheet dependency. */
final class FormsExportRenderer {
    public function render(string $format,array $header,iterable $rows,array $ids=[]):string {
        $chunks=[$this->chunk($format,[$header])];
        if($ids!==[])$chunks[]=$this->chunk($format,[$ids],1);
        foreach($rows as $row)$chunks[]=$this->chunk($format,[$row]);
        return $this->assemble($format,$chunks);
    }
    public function chunk(string $format,iterable $rows,?int $hiddenRow=null):string {
        if(!in_array($format,['csv','ods','xlsx'],true))throw new \InvalidArgumentException('Unsupported Forms export format.');
        $out='';$rowIndex=0;
        foreach($rows as $row) {
            $rowIndex++;
            if($format==='csv') {
                $cells=[];
                foreach($row as $value) {
                    $text=is_array($value)?$value['text']:(string)$value;
                    if(preg_match('/^[\x00-\x20]*[=+\-@]/u',$text)===1||str_starts_with($text,"\t")||str_starts_with($text,"\r"))$text="'".$text;
                    $cells[]='"'.str_replace('"','""',$text).'"';
                }
                $out.=implode(',',$cells)."\r\n";
            } else {
                $hidden=$rowIndex===$hiddenRow;
                $out.=$format==='xlsx'?'<row'.($hidden?' hidden="1"':'').'>':'<table:table-row'.($hidden?' table:visibility="collapse"':'').'>';
                foreach($row as $value) {
                    $text=self::xml(is_array($value)?$value['text']:(string)$value);
                    $url=is_array($value)?($value['url']??null):null;
                    if($url!==null&&(!is_string($url)||!in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)||!is_string(parse_url($url,PHP_URL_HOST))))throw new \InvalidArgumentException('Invalid Forms file-reference URL.');
                    if($format==='xlsx')$out.='<c'.($url!==null?' mcp-href="'.self::xml($url).'"':'').' t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
                    else {
                        $text=str_replace("\n",'<text:line-break/>',$text);
                        if($url!==null)$text='<text:a xlink:type="simple" xlink:href="'.self::xml($url).'">'.$text.'</text:a>';
                        $out.='<table:table-cell office:value-type="string"><text:p xml:space="preserve">'.$text.'</text:p></table:table-cell>';
                    }
                }
                $out.=$format==='xlsx'?'</row>':'</table:table-row>';
            }
        }
        return $out;
    }
    public function assemble(string $format,iterable $chunks):string {
        $body='';foreach($chunks as $chunk){$body.=$chunk;if(strlen($body)>26214400)throw new \RuntimeException('Forms export exceeds the verified output byte quota.');}
        if($format==='csv')return $this->bounded("\xEF\xBB\xBF".$body);
        if($format==='ods')return $this->bounded($this->zip([
            'mimetype'=>'application/vnd.oasis.opendocument.spreadsheet',
            'meta.xml'=>'<?xml version="1.0" encoding="UTF-8"?><office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" office:version="1.2"><office:meta/></office:document-meta>',
            'styles.xml'=>'<?xml version="1.0" encoding="UTF-8"?><office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:xlink="http://www.w3.org/1999/xlink" office:version="1.2"><office:styles/></office:document-styles>',
            'META-INF/manifest.xml'=>'<?xml version="1.0"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/></manifest:manifest>',
            'content.xml'=>'<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:xlink="http://www.w3.org/1999/xlink" office:version="1.2"><office:body><office:spreadsheet><table:table table:name="Results">'.$body.'</table:table></office:spreadsheet></office:body></office:document-content>',
        ]));
        if($format!=='xlsx')throw new \InvalidArgumentException('Unsupported Forms export format.');
        [$body,$hyperlinks,$relationships]=$this->xlsxLinks($body);
        $entries=[
            '[Content_Types].xml'=>'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Results" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml'=>'<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetData>'.$body.'</sheetData>'.$hyperlinks.'</worksheet>',
        ];
        if($relationships!=='')$entries['xl/worksheets/_rels/sheet1.xml.rels']=$relationships;
        return $this->bounded($this->zip($entries));
    }
    private function bounded(string $bytes):string {
        if(strlen($bytes)>26214400)throw new \RuntimeException('Forms export exceeds the verified output byte quota.');
        return $bytes;
    }
    /** Turn only trusted generated cell markers into standard external relationships. */
    private function xlsxLinks(string $body):array {
        $row=0;$column=0;$links='';$rels='';$sequence=0;$output='';
        $reader=new \XMLReader();$previous=libxml_use_internal_errors(true);libxml_clear_errors();
        try {
            if(!$reader->XML('<rows>'.$body.'</rows>','UTF-8',LIBXML_NONET|LIBXML_COMPACT))throw new \RuntimeException('Forms worksheet parsing failed.');
            while($reader->read()) {
                if($reader->nodeType===\XMLReader::ELEMENT&&$reader->depth===1&&$reader->name==='row') {
                    $row++;$column=0;$output.='<row r="'.$row.'"'.($reader->getAttribute('hidden')==='1'?' hidden="1"':'').'>';
                } elseif($reader->nodeType===\XMLReader::ELEMENT&&$reader->depth===2&&$reader->name==='c') {
                    $column++;$n=$column;$letters='';
                    while($n>0){$n--;$letters=chr(65+$n%26).$letters;$n=intdiv($n,26);}
                    $ref=$letters.$row;$url=$reader->getAttribute('mcp-href');
                    if($url!==null) {
                        $id='rId'.++$sequence;$links.='<hyperlink ref="'.$ref.'" r:id="'.$id.'"/>';
                        $rels.='<Relationship Id="'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="'.self::xml($url).'" TargetMode="External"/>';
                    }
                    if($reader->getAttribute('t')!=='inlineStr')throw new \RuntimeException('Invalid Forms worksheet cell.');
                    $output.='<c r="'.$ref.'" t="inlineStr">'.$reader->readInnerXml().'</c>';
                } elseif($reader->nodeType===\XMLReader::END_ELEMENT&&$reader->depth===1&&$reader->name==='row')$output.='</row>';
            }
            if(libxml_get_errors()!==[])throw new \RuntimeException('Forms worksheet parsing failed.');
        } finally {$reader->close();libxml_clear_errors();libxml_use_internal_errors($previous);}
        return [$output,$links===''?'':'<hyperlinks>'.$links.'</hyperlinks>',$rels===''?'':'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>'];
    }
    private static function xml(string $value):string {
        $value=mb_scrub($value,'UTF-8');
        $value=preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$value)??'';
        return htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');
    }
    /** Stored ZIP entries keep generated workbooks portable and avoid untrusted archive extraction. */
    private function zip(array $entries):string {
        $out='';$central='';$count=0;
        foreach($entries as $name=>$content) {
            $size=strlen($content);$crc=crc32($content);$offset=strlen($out);$length=strlen($name);$count++;
            $out.=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,33,$crc,$size,$size,$length,0).$name.$content;
            $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,33,$crc,$size,$size,$length,0,0,0,0,0,$offset).$name;
        }
        return $out.$central.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($central),strlen($out),0);
    }
}
