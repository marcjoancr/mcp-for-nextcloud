<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
/** Validates raw COPYUID scalars before Horde could expand an arbitrary UID set. */
final class MoveProof {
    public static function copyUid(array $raw,string $sourceUid,string $destinationGeneration):?array {
        if(count($raw)!==3||array_keys($raw)!==[0,1,2])return null;
        foreach($raw as &$value){
            if(!is_string($value)&&!is_int($value))return null;$value=(string)$value;
            if(preg_match('/^[1-9][0-9]{0,9}$/D',$value)!==1||(float)$value>4294967295)return null;
        }unset($value);
        if($raw[0]!==$destinationGeneration||$raw[1]!==$sourceUid)return null;
        return ['destination_generation'=>$raw[0],'source_uid'=>$raw[1],'destination_uid'=>$raw[2]];
    }
}
