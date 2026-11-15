<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Reflection only: never resolves a lazy Symfony factory or an app service. */
final class GroupRawRegistry {
    private const EVENTS=[
        'OCP\\Group\\Events\\BeforeGroupCreatedEvent'=>['OCA\\Mcp\\Listener\\GroupLifecycleListener'],
        'OCP\\Group\\Events\\BeforeGroupDeletedEvent'=>['OCA\\Mcp\\Listener\\GroupLifecycleListener','OCA\\Files_Sharing\\Listener\\SharesUpdatedListener','OCA\\Files_External\\Service\\MountCacheService','OC\\Core\\Sharing\\Recipient\\GroupShareRecipientType'],
        'OCP\\Group\\Events\\GroupCreatedEvent'=>['OCA\\Mcp\\Listener\\GroupLifecycleListener','OCA\\Circles\\Listeners\\GroupCreated','OCA\\AdminAudit\\Listener\\GroupManagementEventListener'],
    ];
    private static function read(object $object,string $field):mixed {return (new \ReflectionProperty($object,$field))->getValue($object);}
    /** R2: classify one pinned native direct callback without invoking it or resolving a factory. */
    private static function nativeDirect(string $event,int $priority,\Closure $listener):bool {
        $file=\OC::$SERVERROOT.'/lib/private/Collaboration/Resources/Listener.php';
        if ($event!=='OCP\\Group\\Events\\BeforeGroupDeletedEvent' || $priority!==0 || !is_file($file) || is_link($file) || hash_file('sha256',$file)!=='ef8fe124e85d052213e67c6987b51215d27a0aea7f14b7cebcb68b506e9a7852') {return false;}
        $r=new \ReflectionFunction($listener);$p=$r->getParameters();
        return $r->getFileName()===realpath($file) && $r->getStartLine()===45 && $r->getEndLine()===53
            && $r->getClosureScopeClass()?->getName()==='OC\\Collaboration\\Resources\\Listener'
            && $r->getClosureThis()===null && $r->getStaticVariables()===[] && !$r->isStatic()
            && count($p)===1 && $r->getNumberOfRequiredParameters()===1 && !$p[0]->isPassedByReference() && !$p[0]->isVariadic()
            && $p[0]->getType() instanceof \ReflectionNamedType && $p[0]->getType()->getName()==='OCP\\Group\\Events\\BeforeGroupDeletedEvent'
            && $r->getReturnType() instanceof \ReflectionNamedType && $r->getReturnType()->getName()==='void';
    }
    public static function inspect(\OC\EventDispatcher\EventDispatcher $dispatcher,object $server,?GroupObserver $owned=null):void {
        if ($dispatcher::class!==\OC\EventDispatcher\EventDispatcher::class || $server::class!==\OC\Server::class) {throw new \RuntimeException('Group observer dispatcher is unqualified');}
        $symfony=self::read($dispatcher,'dispatcher');
        if ($symfony::class!==\Symfony\Component\EventDispatcher\EventDispatcher::class) {throw new \RuntimeException('Group observer registry is unqualified');}
        $raw=self::read($symfony,'listeners');
        foreach (self::EVENTS as $event=>$classes) {
            $groups=$raw[$event]??[];$n=0;$seen=[];
            if (!is_array($groups)) {throw new \RuntimeException('Group observer registry is unqualified');}
            foreach ($groups as $priority=>$listeners) {
                if (!is_int($priority) || !is_array($listeners) || !array_is_list($listeners) || ($n+=count($listeners))>16) {throw new \RuntimeException('Group observer registry is unqualified');}
                foreach ($listeners as $position=>$listener) {
                    if ($owned!==null && $priority===PHP_INT_MAX && $position===0 && $listener===$owned) {continue;}
                    if ($listener instanceof \Closure && self::nativeDirect($event,$priority,$listener)) {
                        if(isset($seen['native-direct']))throw new \RuntimeException('Group observer listener is unqualified');$seen['native-direct']=true;continue;
                    }
                    if ($priority===PHP_INT_MAX || !is_object($listener) || $listener::class!==\OC\EventDispatcher\ServiceEventListener::class) {throw new \RuntimeException('Group observer listener is unqualified');}
                    $class=self::read($listener,'class');$service=self::read($listener,'service');
                    if (!in_array($class,$classes,true) || isset($seen[$class]) || self::read($listener,'container')!==$server || self::read($listener,'logger')!==self::read($dispatcher,'logger') || ($service!==null && (!is_object($service) || $service::class!==$class))) {throw new \RuntimeException('Group observer listener is unqualified');}
                    $seen[$class]=true;
                }
            }
            if ($owned!==null && ($groups[PHP_INT_MAX][0]??null)!==$owned) {throw new \RuntimeException('Group observer ordering changed');}
        }
    }
    public static function events():array {return array_keys(self::EVENTS);}
}
