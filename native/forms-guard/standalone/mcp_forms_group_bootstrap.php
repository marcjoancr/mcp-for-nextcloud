<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
// Private bundle is verified before importing declarations; no app discovery/factory/SQL/event at config load.
(static function():void {
    $metadata=__DIR__.'/mcp_forms_group_install.json';$manifest=__DIR__.'/GroupSources.json';
    foreach([$metadata,$manifest]as$file)if(!is_file($file)||is_link($file)||filesize($file)>65536)throw new \RuntimeException('Group observer bootstrap source is unavailable');
    $m=json_decode(file_get_contents($metadata),true,8,JSON_THROW_ON_ERROR);
    if(($m['format']??null)!==1||($m['active']??null)!==true||!is_string($m['manifest_sha256']??null)||!hash_equals($m['manifest_sha256'],hash_file('sha256',$manifest)))throw new \RuntimeException('Group observer bootstrap manifest changed');
    $pins=json_decode(file_get_contents($manifest),true,8,JSON_THROW_ON_ERROR);
    $classes=['GroupProfile','GroupCoreService','GroupRawRegistry','GroupWitnessState','GroupIdentity','GroupWitnessStore','GroupLeaseFlow','GroupLease','GroupObserver','GroupBootstrap'];$expected=[...array_map(static fn(string$c):string=>$c.'.php',$classes),'mcp_forms_group_bootstrap.php','mcp-forms-groups.config.php'];sort($expected,SORT_STRING);
    $names=array_keys($pins['owned']??[]);sort($names,SORT_STRING);
    if($names!==$expected)throw new \RuntimeException('Group observer bootstrap inventory changed');
    foreach($pins['owned']as$name=>$sha){$file=__DIR__.'/'.$name;if(!is_string($sha)||!is_file($file)||is_link($file)||!hash_equals($sha,hash_file('sha256',$file)))throw new \RuntimeException('Group observer bootstrap source changed');}
    foreach($classes as$class)require_once __DIR__.'/'.$class.'.php';
    GroupBootstrap::register();
})();
