<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Content;
final class BodyIndexPolicy {
    public const VERSION='complete-text-v1';
    public static function extract(array $read):array {
        $text=$read['text']??null;
        if(($read['coverage']??null)!=='supported_text_parts_only'||!is_string($text)||!mb_check_encoding($text,'UTF-8')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text))return ['state'=>'refused_content','text'=>''];
        if(($read['truncated']??true)!==false||strlen($text)>65536||mb_strlen($text)>20000)return ['state'=>'refused_size','text'=>''];
        return ['state'=>'complete','text'=>$text];
    }
}
