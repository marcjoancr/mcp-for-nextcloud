<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
/** Native default SQL_PATH includes REMOTE even when that join is not selected. Never allocate it to prove absence. */
final class RemoteJoin {
    private const FIELDS=['id','type','interface','uid','instance','href','item','creation'];
    public static function assertAbsent(array $data,string $prefix):void {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*_$/D',$prefix)){throw new \RuntimeException('Circle remote join prefix is malformed');}
        $found=[];
        foreach($data as$key=>$value){
            if(!is_string($key)||!str_starts_with($key,$prefix)){continue;}
            $field=substr($key,strlen($prefix));
            if(!in_array($field,self::FIELDS,true)||$value!==null){throw new \RuntimeException('Present Circle remote join is not qualified');}
            $found[]=$field;
        }
        // Unselected path: no fields. Selected absent left join: complete native projection, all NULL.
        if($found===[]){return;}
        sort($found,SORT_STRING);$expected=self::FIELDS;sort($expected,SORT_STRING);
        if($found!==$expected){throw new \RuntimeException('Circle remote join is partially bound');}
    }
}
