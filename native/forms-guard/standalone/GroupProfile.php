<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Separate installation activation; never reads or changes comments activation. */
final class GroupProfile {
    public static function assertCurrent():string {
        $path=\OC::$configDir.'/mcp_forms_group_install.json';
        if (!is_file($path) || is_link($path) || filesize($path)>4096) {throw new \RuntimeException('Group observer activation is unavailable');}
        $meta=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if (!is_array($meta) || array_keys($meta)!==['format','active','epoch','manifest_sha256'] || $meta['format']!==1 || $meta['active']!==true || preg_match('/^[a-f0-9]{64}$/D',(string)$meta['epoch'])!==1 || preg_match('/^[a-f0-9]{64}$/D',(string)$meta['manifest_sha256'])!==1) {throw new \RuntimeException('Group observer activation is unavailable');}
        $manifest=__DIR__.'/GroupSources.json';
        if (!is_file($manifest) || is_link($manifest) || filesize($manifest)>65536 || !hash_equals($meta['manifest_sha256'],hash_file('sha256',$manifest))) {throw new \RuntimeException('Group observer source manifest changed');}
        $pins=json_decode(file_get_contents($manifest),true,8,JSON_THROW_ON_ERROR);
        if (!is_array($pins) || array_keys($pins)!==['native','owned'] || count($pins['native'])<12 || count($pins['owned'])<7) {throw new \RuntimeException('Group observer source manifest changed');}
        foreach ($pins as $kind=>$files) {
            foreach ($files as $relative=>$sha) {
                if (!is_string($relative) || str_contains($relative,'..') || str_starts_with($relative,'/') || !is_string($sha)) {throw new \RuntimeException('Group observer source manifest changed');}
                $file=($kind==='native'?\OC::$SERVERROOT:__DIR__).'/'.$relative;
                if (!is_file($file) || is_link($file) || !hash_equals($sha,hash_file('sha256',$file))) {throw new \RuntimeException('Group observer source changed');}
            }
        }
        return $meta['epoch'];
    }
}
