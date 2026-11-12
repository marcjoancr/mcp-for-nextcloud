<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Presentation;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** A review projection only. Never persist or transmit body_html_preview as a draft body. */
final class MailBodyPreview {
    public const MAX_HTML_BYTES = 3145728;
    public static function fields(array $fields, int $reviewLimit = 20000): array {
        $html=(string)($fields['body_html']??'');$plain=(string)($fields['body_plain']??'');
        if(strlen($html)>self::MAX_HTML_BYTES||mb_strlen($plain)>$reviewLimit)throw new ToolFailure('Body exceeds the supported review limit. Use Mail.');
        $images=[];$total=0;
        $preview=preg_replace_callback('~data:(image/(?:png|jpeg|gif|webp));base64,([A-Za-z0-9+/=]+)~i',static function(array $m)use(&$images,&$total):string {
            if(strlen($m[2])<=4096)return $m[0];
            $bytes=base64_decode($m[2],true);
            if($bytes===false||base64_encode($bytes)!==$m[2])throw new ToolFailure('Unsupported embedded image encoding. Use Mail.');
            $total+=strlen($bytes);if(count($images)>=10||$total>2097152)throw new ToolFailure('Embedded image limit exceeded. Use Mail.');
            $sha=hash('sha256',$bytes);$images[]=['mime'=>strtolower($m[1]),'bytes'=>strlen($bytes),'sha256'=>$sha];
            return 'mcp-inline-image:'.$sha;
        },$html);
        if($preview===null||mb_strlen($preview)>$reviewLimit)throw new ToolFailure('Body exceeds the supported review limit. Use Mail.');
        if($images!==[]){
            unset($fields['body_html']);
            $fields['body_html_preview']=$preview;$fields['body_html_sha256']=hash('sha256',$html);
            $fields['body_html_bytes']=strlen($html);$fields['inline_images']=$images;$fields['inline_images_omitted']=true;
        }
        return $fields;
    }
    public static function delivery(array $snapshot): array {
        $fields=self::fields(['body_plain'=>$snapshot['bodyPlain']??'','body_html'=>$snapshot['bodyHtml']??''],80000);
        if(strlen((string)($snapshot['bodyPlain']??''))>80000||strlen((string)($fields['body_html_preview']??$fields['body_html']??''))>80000)throw new ToolFailure('Body exceeds the supported delivery limit. Use Mail.');
        if(isset($fields['body_html_preview'])){
            unset($snapshot['bodyHtml']);$snapshot['bodyHtmlPreview']=$fields['body_html_preview'];
            foreach(['body_html_sha256','body_html_bytes','inline_images','inline_images_omitted'] as $key)$snapshot[$key]=$fields[$key];
        }
        return $snapshot;
    }
}
