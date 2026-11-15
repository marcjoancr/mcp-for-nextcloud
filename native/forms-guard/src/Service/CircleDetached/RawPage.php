<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
use OCA\Forms\Mcp\Service\CircleReadData;
/** Validate native outer-join absence separately from a malformed present Circle. No rows are omitted. */
final class RawPage {
    public static function validate(array $rows):array {
        $validation=$rows;
        foreach($validation as &$row){
            if(!is_array($row)){throw new \RuntimeException('Circle query row is malformed');}
            foreach($row as $key=>$value){
                if($value==='__forms_circle_row_budget_exceeded__'){throw new \RuntimeException('Circle SQL row byte budget exceeded');}
                if(!is_string($key)||$value!==null||!str_ends_with($key,'_settings')){continue;}
                $prefix=substr($key,0,-strlen('settings'));
                if(!array_key_exists($prefix.'unique_id',$row)||$row[$prefix.'unique_id']!==null){throw new \RuntimeException('Present Circle has no settings');}
                foreach($row as $other=>$field){if(is_string($other)&&str_starts_with($other,$prefix)&&$field!==null){throw new \RuntimeException('Absent Circle join contains bound fields');}}
                unset($row[$key]);
            }
        }
        unset($row);
        CircleReadData::page($validation);
        return $rows;
    }
}
