<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mail\Service;
/** Bounded raster data images become related MIME parts, as in the native composer. */
final class FrozenInlineImages {
    public function extract(string $html): array {
        if(strlen($html)>3145728)throw new \RuntimeException('HTML body bound exceeded.');
        if(!str_contains(strtolower($html),'data:')){if(strlen($html)>80000)throw new \RuntimeException('HTML text bound exceeded.');return [$html,[]];}
        $doc=new \DOMDocument();$previous=libxml_use_internal_errors(true);
        try{$ok=$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET);}
        finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        if(!$ok)throw new \RuntimeException('Unsupported HTML body.');
        $parts=[];$total=0;
        foreach($doc->getElementsByTagName('img') as $image){
            $src=$image->getAttribute('src');if(!str_starts_with(strtolower($src),'data:'))continue;
            if(preg_match('~^data:(image/(?:png|jpeg|gif|webp));base64,([A-Za-z0-9+/=]+)$~iD',$src,$m)!==1)throw new \RuntimeException('Unsupported embedded image.');
            $bytes=base64_decode($m[2],true);
            if($bytes===false||$bytes===''||base64_encode($bytes)!==$m[2])throw new \RuntimeException('Unsupported embedded image encoding.');
            $total+=strlen($bytes);if(count($parts)>=10||$total>2097152)throw new \RuntimeException('Embedded image bound exceeded.');
            $cid='mcp-inline-'.bin2hex(random_bytes(24)).'@mcp.invalid';
            $parts[]='Content-Type: '.strtolower($m[1])."\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: inline\r\nContent-ID: <".$cid.">\r\n\r\n".chunk_split(base64_encode($bytes),76,"\r\n");
            $image->setAttribute('src','cid:'.$cid);
        }
        if($parts===[]){if(strlen($html)>80000)throw new \RuntimeException('HTML text bound exceeded.');return [$html,[]];}
        $body=$doc->getElementsByTagName('body')->item(0);if($body===null)throw new \RuntimeException('Unsupported HTML document.');
        $out='';foreach($body->childNodes as $child)$out.=$doc->saveHTML($child);
        // Binary data is outside the HTML text budget; visible content is never truncated.
        if(strlen($out)>80000)throw new \RuntimeException('HTML text bound exceeded.');
        return [$out,$parts];
    }
}
