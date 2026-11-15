<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
/** Exact executable inventory, including native loader/routes/templates as well as library classes. */
final class SourceTree {
    public static function assertExact(string $root,array $map):void {
        $found=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $entry){
            $relative=substr($entry->getPathname(),strlen($root)+1);
            if($entry->isLink()){throw new \RuntimeException('Circle source symlink is not qualified');}
            if($entry->isFile()&&(str_ends_with($relative,'.php')||$relative==='appinfo/info.xml')){$found[]=$relative;}
        }
        sort($found,SORT_STRING);$expectedPaths=array_keys($map);sort($expectedPaths,SORT_STRING);
        if($found!==$expectedPaths){throw new \RuntimeException('Circle executable source set changed');}
        foreach($map as $relative=>$expected){
            $path=$root.'/'.$relative;
            if(is_link($path)||!is_file($path)||!is_string($expected)||!hash_equals($expected,hash_file('sha256',$path))){throw new \RuntimeException('Circle source fingerprint changed');}
        }
    }
}
