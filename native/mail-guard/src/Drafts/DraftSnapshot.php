<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Drafts;
/** Internal byte-complete revision, never persisted, logged or exposed to MCP. */
final class DraftSnapshot {
    public static function of(object $message):string {
        $row=$message->jsonSerialize();$row['__mcp_draft_only']=(bool)$message->getMcpDraftOnly();
        foreach(['attachments','from','to','cc','bcc'] as $field){
            if(!isset($row[$field]))continue;
            $children=array_map(static function(mixed $child):mixed {
                $value=$child instanceof \JsonSerializable?$child->jsonSerialize():$child;
                if(is_array($value))ksort($value);return $value;
            },$row[$field]);
            usort($children,static fn(mixed $a,mixed $b):int=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));$row[$field]=$children;
        }
        ksort($row);$hash=hash_init('sha256');
        foreach($row as $key=>$value){
            hash_update($hash,json_encode([$key,get_debug_type($value)],JSON_THROW_ON_ERROR));
            // Avoid an extra MIME/body-sized JSON copy, including arbitrary raw bytes.
            $bytes=is_string($value)?$value:json_encode($value,JSON_THROW_ON_ERROR);
            hash_update($hash,(string)strlen($bytes).':');hash_update($hash,$bytes);
        }
        return hash_final($hash);
    }
}
