<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;

/** Copies only an exact pinned native object graph and replaces selected request dependencies before first use. */
final class CircleNativeGraph {
    public static function copy(object $source,array $replace):object {
        $reflection=new \ReflectionClass($source);$copy=$reflection->newInstanceWithoutConstructor();$used=[];
        for($class=$reflection;$class!==false;$class=$class->getParentClass()){
            foreach($class->getProperties() as $property){if($property->getDeclaringClass()->getName()!==$class->getName())continue;$key=$class->getName().'::'.$property->getName();
                if($property->isStatic()){
                    if(array_key_exists($property->getName(),$replace)){throw new \RuntimeException('Native Circle graph cannot replace static state');}
                    continue;
                }
                if(array_key_exists($property->getName(),$replace)){$value=$replace[$property->getName()];$used[$property->getName()]=true;}
                else{try{$value=$property->getValue($source);}catch(\Error){continue;}}
                $property->setValue($copy,$value);
            }
        }
        foreach($replace as $name=>$value){if(!isset($used[$name]))throw new \RuntimeException('Native Circle graph replacement field is unavailable');}
        return$copy;
    }
}
