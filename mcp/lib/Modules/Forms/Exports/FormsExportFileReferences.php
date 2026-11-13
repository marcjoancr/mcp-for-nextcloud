<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Consume native reference pages before declaring a submission's authorized file projection complete. */
final class FormsExportFileReferences {
    public static function collect(\Closure $page,\Closure $visible,?\Closure $checkpoint=null):array {
        $started=microtime(true);$offset=0;$bytes=0;$out=[];
        $check=static function()use($started,$checkpoint):void {
            if(microtime(true)-$started>20)throw new FormsExportBudgetExceeded('Forms file-reference collection needs a bounded continuation.');
            if($checkpoint!==null)$checkpoint();
        };
        do {
            $check();$raw=$page($offset,100);$check();$refs=$raw['files']??null;
            if(!is_array($refs)||count($refs)>100)throw new ToolFailure('The native Forms file-reference page exceeds its bound.');
            $bytes+=strlen(json_encode($raw,JSON_THROW_ON_ERROR));
            if($bytes>1048576)throw new ToolFailure('Forms file-reference metadata exceeds its projection budget.');
            $ids=[];
            foreach($refs as $ref) {
                if(!is_array($ref)||!is_int($ref['file_id']??null)||$ref['file_id']<1)throw new ToolFailure('A native Forms file reference is unavailable.');
                $ids[$ref['file_id']]=true;
            }
            foreach($visible($refs) as $file) {
                if(!is_array($file)||!isset($ids[$file['file_id']??0]))throw new ToolFailure('Forms file visibility returned an unrelated reference.');
                $out[$file['file_id']]=$file;
            }
            $check();
            if(strlen(json_encode($out,JSON_THROW_ON_ERROR))>1048576)throw new ToolFailure('Forms visible file references exceed their projection budget.');
            if(!array_key_exists('_mcp_page',$raw)) {
                if($offset!==0)throw new ToolFailure('Native Forms file continuation disappeared.');
                break; // Independently pinned older companion returned its complete <=100-reference envelope.
            }
            $meta=$raw['_mcp_page'];
            if(!is_array($meta)||($meta['offset']??null)!==$offset||!is_bool($meta['hasMore']??null)
                ||!array_key_exists('nextOffset',$meta)
                ||($meta['hasMore']?($refs===[]||$meta['nextOffset']!==$offset+count($refs)):$meta['nextOffset']!==null))
                throw new ToolFailure('Native Forms file continuation is invalid.');
            if(!$meta['hasMore'])break;
            $offset=$meta['nextOffset'];
        } while(true);
        return array_values($out);
    }
}
