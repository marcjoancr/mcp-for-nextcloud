<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Read an already constructed Core singleton without invoking its factory/alias. */
final class GroupCoreService {
    public static function existing(\OC\Server $server,string $class):object {
        if ($server::class!==\OC\Server::class) {throw new \RuntimeException('Group observer server is unqualified');}
        $pimple=(new \ReflectionProperty(\OC\AppFramework\Utility\SimpleContainer::class,'container'))->getValue($server);
        if ($pimple::class!==\Pimple\Container::class) {throw new \RuntimeException('Group observer container is unqualified');}
        $values=(new \ReflectionProperty(\Pimple\Container::class,'values'))->getValue($pimple);$value=$values[$class]??null;
        if (is_object($value) && $value::class===$class) {return $value;}
        if (!$value instanceof \Closure) {throw new \RuntimeException('Group observer Core service is not initialized');}
        $file=(new \ReflectionClass(\OC\AppFramework\Utility\SimpleContainer::class))->getFileName();
        $outer=new \ReflectionFunction($value);$vars=$outer->getStaticVariables();
        if ($outer->getFileName()!==$file || array_keys($vars)!==['closure'] || !$vars['closure'] instanceof \Closure) {throw new \RuntimeException('Group observer Core service is not initialized');}
        $inner=new \ReflectionFunction($vars['closure']);$vars=$inner->getStaticVariables();
        if ($inner->getFileName()!==$file || array_keys($vars)!==['object'] || !is_object($vars['object']) || $vars['object']::class!==$class) {throw new \RuntimeException('Group observer Core service is not initialized');}
        return $vars['object'];
    }
}
