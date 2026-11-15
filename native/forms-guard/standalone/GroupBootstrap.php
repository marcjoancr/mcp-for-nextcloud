<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Config-time registration only; original Core group declarations remain untouched. */
final class GroupBootstrap {
    private static bool $registered=false;
    private static bool $entering=false;
    private static ?\OC\Server $server=null;
    private static ?\OC\EventDispatcher\EventDispatcher $dispatcher=null;
    private static ?GroupObserver $observer=null;
    public static function register():void {
        if (self::$registered || !in_array(PHP_SAPI,['apache2handler','fpm-fcgi','cli'],true) || (string)ini_get('opcache.preload')!=='' || getenv('FRANKENPHP_WORKER')!==false || class_exists('OC\\Group\\Manager',false) || class_exists('OC\\Group\\Group',false)) {throw new \RuntimeException('Group observer request lifetime is unqualified');}
        self::$registered=true;spl_autoload_register(self::load(...),true,true);
    }
    public static function load(string $class):void {
        $paths=['OC\\Group\\Manager'=>'lib/private/Group/Manager.php','OC\\Group\\Group'=>'lib/private/Group/Group.php'];
        if (!isset($paths[$class])) {return;}
        if (self::$entering) {throw new \RuntimeException('Group observer bootstrap reentered');}self::$entering=true;
        try {
            GroupProfile::assertCurrent();$server=\OC::$server;
            if (!$server instanceof \OC\Server || $server::class!==\OC\Server::class) {throw new \RuntimeException('Group observer Core server is unavailable');}
            $dispatcher=GroupCoreService::existing($server,\OC\EventDispatcher\EventDispatcher::class);
            if (self::$server!==null && (self::$server!==$server || self::$dispatcher!==$dispatcher)) {throw new \RuntimeException('Group observer Core request changed');}
            if (self::$observer===null) {
                GroupRawRegistry::inspect($dispatcher,$server);
                self::$server=$server;self::$dispatcher=$dispatcher;self::$observer=new GroupObserver($server,$dispatcher);
                foreach (GroupRawRegistry::events() as $event) {$dispatcher->addListener($event,self::$observer,PHP_INT_MAX);}
            }
            GroupRawRegistry::inspect($dispatcher,$server,self::$observer);
            $file=\OC::$SERVERROOT.'/'.$paths[$class];require_once $file;
            if (!class_exists($class,false) || (new \ReflectionClass($class))->getFileName()!==realpath($file)) {throw new \RuntimeException('Group observer native class source changed');}
        } finally {self::$entering=false;}
    }
    public static function assertActive():void {
        GroupProfile::assertCurrent();
        if (self::$observer===null || self::$server!==\OC::$server || GroupCoreService::existing(self::$server,\OC\EventDispatcher\EventDispatcher::class)!==self::$dispatcher) {throw new \RuntimeException('Group observer is unavailable');}
        GroupRawRegistry::inspect(self::$dispatcher,self::$server,self::$observer);
    }
}
