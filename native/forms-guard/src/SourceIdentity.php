<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp;
final class SourceIdentity {
    private static ?\Closure $verifiedReadFrame=null;
    /** Only for a closed read batch: authority stays fresh; code is checked at both boundaries. */
    public static function withinVerifiedReadFrame(\Closure $frame, \Closure $read): mixed {
        if (self::$verifiedReadFrame!==null) {throw new \RuntimeException('Nested native source read frame is unavailable');}
        $frame();
        if (!self::verify()) {throw new \RuntimeException('Native source is unavailable before final read');}
        self::$verifiedReadFrame=$frame;
        try {return $read();}
        finally {
            self::$verifiedReadFrame=null;
            $frame();
            if (!self::verify()) {throw new \RuntimeException('Native source changed during final read');}
        }
    }
    public static function verify(): bool {
        if (self::$verifiedReadFrame!==null) {(self::$verifiedReadFrame)();return true;}
        $root=dirname(__DIR__,2);
        $path=$root.'/mcp-guard-installed.json';
        if (!is_file($path)) {return false;}
        try {$manifest=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);} catch (\Throwable) {return false;}
        if (($manifest['protocol']??null)!==1 || ($manifest['formsVersion']??'')!=='5.4.2') {return false;}
        foreach ($manifest['sha256']??[] as $relative=>$hash) {
            if (!is_string($relative) || str_contains($relative,'..') || str_starts_with($relative,'/') || is_link($root.'/'.$relative) || !is_file($root.'/'.$relative) || !hash_equals($hash,hash_file('sha256',$root.'/'.$relative))) {return false;}
        }
        $expected=$manifest['sha256']??[];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if ($entry->isLink()) {return false;}
            $relative=substr($entry->getPathname(),strlen($root)+1);
            if ($entry->isFile() && preg_match('/\.(php|xml|json)$/D',$relative) && $relative!=='mcp-guard-installed.json' && !array_key_exists($relative,$expected)) {return false;}
        }
        return count($expected)>50;
    }
}
