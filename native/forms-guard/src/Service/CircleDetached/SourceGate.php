<?php
/* UNAPPLIED R2 proposal. SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
final class SourceGate {
    public function assert(string $uid): void {
        $core=new \OCA\Forms\Mcp\Service\FreshCore($uid);
        if(!$core->apps()->isEnabledForUser('circles',$core->user())||$core->appConfig()->getValueString('circles','installed_version')!=='35.0.0'){throw new \RuntimeException('Circle sources are not qualified');}
        $root=realpath($core->apps()->getAppPath('circles'));
        if(!is_string($root)){throw new \RuntimeException('Circle source path is unavailable');}
        $map=json_decode(file_get_contents(dirname(__DIR__,2).'/CircleSources.json'),true,32,JSON_THROW_ON_ERROR);
        $additional=json_decode(file_get_contents(__DIR__.'/AdditionalExecutableSources.json'),true,32,JSON_THROW_ON_ERROR);
        if(array_intersect_key($map,$additional)!==[]){throw new \RuntimeException('Circle executable source maps overlap');}
        $map+=$additional;
        SourceTree::assertExact($root,$map);
        foreach(StaticDefaults::EXPECTED as $class=>$properties){
            foreach($properties as $name=>$expected){if((new \ReflectionProperty($class,$name))->getValue()!==$expected){throw new \RuntimeException('Native Circle static query/model defaults changed');}}
        }
        foreach(['CoreQueryBuilder'=>'Db','Circle'=>'Model','Member'=>'Model'] as $short=>$area){
            foreach(StaticDefaults::EXPECTED['OCA\\Circles\\'.$area.'\\'.$short] as $name=>$expected){if((new \ReflectionProperty(__NAMESPACE__.'\\'.$short,$name))->getValue()!==$expected){throw new \RuntimeException('Owned Circle static defaults changed');}}
        }
        $classes=json_decode(file_get_contents(__DIR__.'/NativeClasses.json'),true,32,JSON_THROW_ON_ERROR);
        foreach($classes as $class=>$path){
            if(!isset($map[$path])||realpath((new \ReflectionClass($class))->getFileName())!==realpath($root.'/'.$path)){throw new \RuntimeException('Circle native class resolves outside qualified source');}
        }
    }
}
