<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Exact source and listener closure; unknown callbacks/aliases never become omitted policy. */
final class FinalShareProfile {
    private const HANDLERS=[\OC\Core\Listener\RestrictInteractionListener::class,\OCA\Files\Listener\RestrictInteractionListener::class,\OCA\Files_Sharing\Listener\RestrictInteractionListener::class];
    public static function assertCurrent():void {
        $pins=json_decode(file_get_contents(__DIR__.'/FinalShareSources.json'),true,64,JSON_THROW_ON_ERROR);
        if (!is_array($pins) || count($pins)<20) {throw new \RuntimeException('Final sharing source profile is unavailable');}
        foreach ($pins as $path=>$sha) {
            $file=\OC::$SERVERROOT.'/'.$path;
            if (!is_string($path) || str_contains($path,'..') || !is_string($sha) || !is_file($file) || is_link($file) || !hash_equals($sha,hash_file('sha256',$file))) {throw new \RuntimeException('Final sharing source is unqualified');}
        }
        self::resourceOrder();
    }
    private static function read(object $object,string $name):mixed {return (new \ReflectionProperty($object,$name))->getValue($object);}
    /** Do not call a registered closure to discover what it does. */
    private static function definition(\OC\AppFramework\Utility\SimpleContainer $container,string $class):void {
        $pimple=(new \ReflectionProperty(\OC\AppFramework\Utility\SimpleContainer::class,'container'))->getValue($container);
        if ($pimple::class!==\Pimple\Container::class) {throw new \RuntimeException('Final sharing listener container is unqualified');}
        if (!isset($pimple[$class])) {return;}
        $wrapper=$pimple->raw($class);
        if (!$wrapper instanceof \Closure) {throw new \RuntimeException('Final sharing listener definition is unqualified');}
        $r=new \ReflectionFunction($wrapper);$vars=$r->getStaticVariables();
        $file=(new \ReflectionClass(\OC\AppFramework\Utility\SimpleContainer::class))->getFileName();
        if ($r->getFileName()!==$file || !isset($vars['closure']) || !$vars['closure'] instanceof \Closure) {throw new \RuntimeException('Final sharing listener definition is unqualified');}
        $inner=new \ReflectionFunction($vars['closure']);$values=$inner->getStaticVariables();
        // The ONLY registered definition admitted is SimpleContainer's own cached auto-wired object.
        if ($inner->getFileName()!==$file || array_keys($values)!==['object'] || !is_object($values['object']) || $values['object']::class!==$class) {throw new \RuntimeException('Final sharing listener alias is unqualified');}
    }
    private static function rawListeners(object $dispatcher,string $event):array {
        $registry=(new \ReflectionProperty(\Symfony\Component\EventDispatcher\EventDispatcher::class,'listeners'))->getValue($dispatcher);
        $groups=$registry[$event]??[];$count=0;$out=[];
        if (!is_array($groups)) {throw new \RuntimeException('Final sharing listener registry is unqualified');}
        foreach ($groups as $priority=>$listeners) {
            if (!is_int($priority) || !is_array($listeners) || !array_is_list($listeners) || ($count+=count($listeners))>3) {throw new \RuntimeException('Final sharing listener registry is unqualified');}
            foreach ($listeners as $listener) {if (!is_object($listener) || $listener::class!==\OC\EventDispatcher\ServiceEventListener::class) {throw new \RuntimeException('Final sharing listener is unqualified');}}
        }
        krsort($groups,SORT_NUMERIC);foreach ($groups as $listeners) {array_push($out,...$listeners);}return $out;
    }
    public static function resourceOrder():array {
        $dispatcher=\OCP\Server::get(\OCP\EventDispatcher\IEventDispatcher::class);
        if ($dispatcher::class!==\OC\EventDispatcher\EventDispatcher::class) {throw new \RuntimeException('Final sharing dispatcher is unqualified');}
        $symfony=$dispatcher->getSymfonyDispatcher();
        if ($symfony::class!==\Symfony\Component\EventDispatcher\EventDispatcher::class) {throw new \RuntimeException('Final sharing listener registry is unqualified');}
        $seen=[];$resources=[];
        foreach (self::rawListeners($symfony,\OCP\Interaction\RestrictInteractionEvent::class) as $listener) {
            if (!is_object($listener) || $listener::class!==\OC\EventDispatcher\ServiceEventListener::class) {throw new \RuntimeException('Final sharing listener is unqualified');}
            $class=self::read($listener,'class');$container=self::read($listener,'container');$service=self::read($listener,'service');
            if (!is_string($class) || !in_array($class,self::HANDLERS,true) || isset($seen[$class]) || $container!==\OC::$server || $container::class!==\OC\Server::class || ($service!==null && $service::class!==$class)) {throw new \RuntimeException('Final sharing listener is unqualified');}
            self::definition($container,$class);
            if (str_starts_with($class,'OCA\\')) {
                $namespace=strtolower(substr($class,0,strpos($class,'\\',4)));
                $apps=(new \ReflectionProperty(\OC\ServerContainer::class,'appContainers'))->getValue($container);
                $app=$apps[$namespace]??null;
                if (!$app instanceof \OC\AppFramework\DependencyInjection\DIContainer || $app::class!==\OC\AppFramework\DependencyInjection\DIContainer::class) {throw new \RuntimeException('Final sharing listener app container is unavailable');}
                self::definition($app,$class);
            }
            $seen[$class]=true;
            if ($class!==self::HANDLERS[0]) {$resources[]=$class;}
        }
        if (count($seen)!==count(self::HANDLERS)) {throw new \RuntimeException('Final sharing listener inventory changed');}
        return $resources;
    }
}
