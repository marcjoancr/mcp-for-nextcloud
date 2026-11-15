<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require (require __DIR__.'/repository-root.php').'/mcp/vendor/autoload.php';
// Pure representation test: explicit inert Core container/dispatcher interfaces, not compatibility evidence.
eval('namespace OC; final class Server implements \\Psr\\Container\\ContainerInterface {public function get(string $id):mixed {throw new \\LogicException("container factory trap");} public function has(string $id):bool{return false;}}');
require dirname(__DIR__).'/references/lib__public__EventDispatcher__IEventDispatcher.php';
eval('namespace Symfony\\Component\\EventDispatcher; interface EventDispatcherInterface {}');
require dirname(__DIR__).'/references/3rdparty__symfony__event-dispatcher__EventDispatcher.php';
require dirname(__DIR__).'/references/lib__private__EventDispatcher__ServiceEventListener.php';
require dirname(__DIR__).'/references/lib__private__EventDispatcher__EventDispatcher.php';
require dirname(__DIR__).'/standalone/GroupRawRegistry.php';
require dirname(__DIR__).'/standalone/GroupObserver.php';
use McpFormsServerExtension\{GroupRawRegistry as Registry,GroupObserver};
eval('class OC {public static string $SERVERROOT;}');\OC::$SERVERROOT='/unqualified-synthetic-root';
$n=0;$calls=0;
function check(bool $v):void{global $n;if(!$v)throw new RuntimeException('test failed');++$n;}
function denied(Closure $f,string $expected):void{$m=null;try{$f();}catch(RuntimeException $e){$m=$e->getMessage();}check($m===$expected);}
$server=new \OC\Server();$logger=new \Psr\Log\NullLogger();$raw=new \Symfony\Component\EventDispatcher\EventDispatcher();$dispatcher=new \OC\EventDispatcher\EventDispatcher($raw,$server,$logger);
Registry::inspect($dispatcher,$server);check(true);
$event='OCP\\Group\\Events\\BeforeGroupDeletedEvent';
$factory=static function()use(&$calls){++$calls;return new stdClass();};$raw->addListener($event,[$factory,'handle']);
$before=(new ReflectionProperty($raw,'listeners'))->getValue($raw);
denied(fn()=>Registry::inspect($dispatcher,$server),'Group observer listener is unqualified');check($calls===0);check($before===(new ReflectionProperty($raw,'listeners'))->getValue($raw));
$raw=new \Symfony\Component\EventDispatcher\EventDispatcher();$dispatcher=new \OC\EventDispatcher\EventDispatcher($raw,$server,$logger);
$known=new \OC\EventDispatcher\ServiceEventListener($server,'OCA\\Files_Sharing\\Listener\\SharesUpdatedListener',$logger);$raw->addListener($event,$known);
Registry::inspect($dispatcher,$server);check($calls===0);
$owned=new GroupObserver($server,$dispatcher);foreach(Registry::events() as $e){$raw->addListener($e,$owned,PHP_INT_MAX);}
Registry::inspect($dispatcher,$server,$owned);check(true);
$raw->addListener($event,$known,PHP_INT_MAX);denied(fn()=>Registry::inspect($dispatcher,$server,$owned),'Group observer listener is unqualified');check($calls===0);
$raw=new \Symfony\Component\EventDispatcher\EventDispatcher();$dispatcher=new \OC\EventDispatcher\EventDispatcher($raw,$server,$logger);
$unknown=new \OC\EventDispatcher\ServiceEventListener($server,'Unqualified\\Listener',$logger);$raw->addListener($event,$unknown);
denied(fn()=>Registry::inspect($dispatcher,$server),'Group observer listener is unqualified');check($calls===0);
$raw=new \Symfony\Component\EventDispatcher\EventDispatcher();$dispatcher=new \OC\EventDispatcher\EventDispatcher($raw,$server,$logger);
$raw->addListener($event,new \OC\EventDispatcher\ServiceEventListener(new \OC\Server(),'OCA\\Files_Sharing\\Listener\\SharesUpdatedListener',$logger));
denied(fn()=>Registry::inspect($dispatcher,$server),'Group observer listener is unqualified');check($calls===0);
$root=sys_get_temp_dir().'/group-closure-pure-'.bin2hex(random_bytes(5));mkdir($root.'/lib/private/Collaboration/Resources',0700,true);
$native=$root.'/lib/private/Collaboration/Resources/Listener.php';copy(dirname(__DIR__).'/references/lib__private__Collaboration__Resources__Listener.php',$native);\OC::$SERVERROOT=$root;
try {
    require $native;
    $raw=new \Symfony\Component\EventDispatcher\EventDispatcher();$dispatcher=new \OC\EventDispatcher\EventDispatcher($raw,$server,$logger);
    \OC\Collaboration\Resources\Listener::register($dispatcher);
    Registry::inspect($dispatcher,$server);check(true);check($calls===0);
    $listeners=(new ReflectionProperty($raw,'listeners'))->getValue($raw);$literal=$listeners[$event][0][0];
    $raw->addListener($event,$literal);denied(fn()=>Registry::inspect($dispatcher,$server),'Group observer listener is unqualified');
    check($calls===0);
}finally{unlink($native);rmdir(dirname($native));rmdir($root.'/lib/private/Collaboration');rmdir($root.'/lib/private');rmdir($root.'/lib');rmdir($root);}
echo json_encode(['ok'=>true,'checks'=>$n,'factory_invocations'=>$calls],JSON_THROW_ON_ERROR)."\n";
