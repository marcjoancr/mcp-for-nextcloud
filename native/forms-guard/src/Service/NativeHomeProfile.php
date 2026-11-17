<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Exact stock callback selectors and code. Captured request values never become a source pin. */
final class NativeHomeProfile {
    private static ?array $owned=null;
    private static ?array $providerOrder=null;
    private static bool $failed=false;
    private static function property(object $object,string $name):mixed{$class=new \ReflectionClass($object);do{if($class->hasProperty($name))return$class->getProperty($name)->getValue($object);$class=$class->getParentClass();}while($class!==false);throw new \RuntimeException('Native Home registry field changed');}
    public static function registerOwnedWrapper(string $name,\Closure $wrapper):void {
        if(self::$owned!==null||$name!=='forms_native_home_guard_v1')throw new \RuntimeException('Native Home guard registration changed');
        $reflection=new \ReflectionFunction($wrapper);
        if($reflection->getClosureScopeClass()?->getName()!==NativeHomeProcess::class
            ||$reflection->getFileName()!==(new \ReflectionClass(NativeHomeProcess::class))->getFileName())throw new \RuntimeException('Native Home guard source changed');
        self::$owned=['name'=>$name,'wrapper'=>$wrapper];
    }
    private static function source(\ReflectionFunctionAbstract $method,string $hash,int $start,int $end):void {
        $file=$method->getFileName();if(!is_string($file)||is_link($file)||!is_file($file)||filesize($file)>8388608
            ||$method->getStartLine()!==$start||$method->getEndLine()!==$end||!hash_equals($hash,hash_file('sha256',$file)))throw new \RuntimeException('Native Home callback source changed');
    }
    private static function classSource(string $class,string $hash):void {
        $file=(new \ReflectionClass($class))->getFileName();if(!is_string($file)||is_link($file)||!is_file($file)||filesize($file)>8388608||!hash_equals($hash,hash_file('sha256',$file)))throw new \RuntimeException('Native Home class source changed');
    }
    private static function definition(\OC\AppFramework\Utility\SimpleContainer $container,string $class):void {
        $pimple=self::property($container,'container');if($pimple::class!==\Pimple\Container::class)throw new \RuntimeException('Native Home listener container changed');
        if(!isset($pimple[$class]))return;$wrapper=$pimple->raw($class);
        if(!$wrapper instanceof \Closure)throw new \RuntimeException('Native Home listener definition changed');
        $reflection=new \ReflectionFunction($wrapper);$values=$reflection->getStaticVariables();$file=(new \ReflectionClass(\OC\AppFramework\Utility\SimpleContainer::class))->getFileName();
        if($reflection->getFileName()!==$file||!($values['closure']??null) instanceof \Closure)throw new \RuntimeException('Native Home listener definition changed');
        $inner=new \ReflectionFunction($values['closure']);$values=$inner->getStaticVariables();
        if($inner->getFileName()!==$file||array_keys($values)!==['object']||!is_object($values['object'])||$values['object']::class!==$class)throw new \RuntimeException('Native Home listener alias changed');
    }
    private static function callback(mixed $listener,array $expected):void {
        $class=$expected['class'];$method=$expected['method'];self::classSource($class,$expected['class_sha256']);
        if($expected['kind']==='service_listener'){
            if(!is_object($listener)||$listener::class!==\OC\EventDispatcher\ServiceEventListener::class||self::property($listener,'class')!==$class||self::property($listener,'container')!==\OC::$server)throw new \RuntimeException('Native Home listener selector changed');
            $service=self::property($listener,'service');if($service!==null&&$service::class!==$class)throw new \RuntimeException('Native Home resolved listener changed');
            self::definition(\OC::$server,$class);
            if(str_starts_with($class,'OCA\\')){$namespace=strtolower(substr($class,0,strpos($class,'\\',4)));$containers=self::property(\OC::$server,'appContainers');$container=$containers[$namespace]??null;if(!$container instanceof \OC\AppFramework\DependencyInjection\DIContainer||$container::class!==\OC\AppFramework\DependencyInjection\DIContainer::class)throw new \RuntimeException('Native Home app container changed');self::definition($container,$class);}
            $reflection=new \ReflectionMethod($class,$method);
        }elseif(in_array($expected['kind'],['callable','closure'],true)&&$listener instanceof \Closure){
            $reflection=new \ReflectionFunction($listener);$shape=$expected['capture_shape'];$boundClass=$shape['bound_class']??$class;unset($shape['bound_class']);
            if($method!=='{closure}'||$reflection->getClosureScopeClass()?->getName()!==$class
                ||($reflection->getClosureThis()===null?null:$reflection->getClosureThis()::class)!==$boundClass)throw new \RuntimeException('Native Home callback binding changed');
            if($boundClass!==$class){$boundHash=$expected['bound_class_sha256']??null;if(!is_string($boundHash)||!preg_match('/^[a-f0-9]{64}$/D',$boundHash))throw new \RuntimeException('Native Home callback bound class is unpinned');self::classSource($boundClass,$boundHash);}
            $actual=$reflection->getStaticVariables();
            if(array_keys($actual)!==array_keys($shape))throw new \RuntimeException('Native Home closure selectors changed');
            foreach($actual as$key=>$value){$want=$shape[$key];if(($want['type']??null)==='bool'){if(!is_bool($value))throw new \RuntimeException('Native Home closure value changed');}else{if(is_object($value)||is_array($value)||is_resource($value)||get_debug_type($value)!==($want['type']??null)||hash('sha256',json_encode($value,JSON_THROW_ON_ERROR))!==($want['sha256']??null))throw new \RuntimeException('Native Home closure value changed');}}
        }else throw new \RuntimeException('Native Home callback shape changed');
        self::source($reflection,$expected['source_sha256'],$expected['method_start'],$expected['method_end']);
    }
    private static function eventSlotMatches(mixed $expected,mixed $priority,mixed $position):bool {
        return is_array($expected)&&($expected['priority']??null)===$priority&&($expected['position']??null)===$position;
    }
    private static function providerOrderMatches(array $expectedRows,array $actualClasses,array $allowedOrders,?array $selectedOrder=null):bool {
        if(!array_is_list($expectedRows)||!array_is_list($actualClasses)||!array_is_list($allowedOrders)
            ||count($expectedRows)>64||count($actualClasses)>64||count($allowedOrders)<1||count($allowedOrders)>4)return false;
        $expectedClasses=array_column($expectedRows,'class');
        if(count($expectedClasses)!==count($expectedRows)||count($expectedClasses)!==count($actualClasses)
            ||array_filter($expectedClasses,static fn(mixed $class):bool=>!is_string($class))!==[])return false;
        if(count(array_unique($expectedClasses))!==count($expectedClasses))return false;
        $canonical=$expectedClasses;sort($canonical,SORT_STRING);
        foreach($allowedOrders as$order){
            if(!is_array($order)||!array_is_list($order)||count($order)!==count($expectedClasses)
                ||array_filter($order,static fn(mixed $class):bool=>!is_string($class))!==[])return false;
            $sorted=$order;sort($sorted,SORT_STRING);
            if($sorted!==$canonical)return false;
        }
        return in_array($actualClasses,$allowedOrders,true)
            &&($selectedOrder===null||in_array($selectedOrder,$allowedOrders,true)&&$actualClasses===$selectedOrder);
    }
    /** Pinned registration-only Core method; existing mounts must still be empty. */
    public static function prepareBuiltinWrappers():void {
        if(self::$failed)throw new \RuntimeException('Native Home profile is latched');
        try {
            $expected=self::expected();
            $manager=\OCP\Server::get(\OCP\Files\Mount\IMountManager::class);
            if($manager::class!==\OC\Files\Mount\Manager::class||$manager->getAll()!==[])throw new \RuntimeException('Native Home setup is already materialized');
            $setup=$manager->getSetupManager();if($setup::class!==\OC\Files\SetupManager::class)throw new \RuntimeException('Native Home setup manager changed');
            $apps=self::property($setup,'appManager');if($apps::class!==\OC\App\AppManager::class)throw new \RuntimeException('Native Home app registration changed');
            (new \ReflectionMethod(\OC\Files\SetupManager::class,'setupBuiltinWrappers'))->invoke($setup,[]);
            self::assertCurrent();
        } catch(\Throwable $error){self::$failed=true;throw new \RuntimeException('Native Home builtin registration failed',0,$error);}
    }
    private static function expected():array {
        $path=__DIR__.'/NativeHomeSources.json';if(is_link($path)||!is_file($path)||filesize($path)>131072)throw new \RuntimeException('Native Home source profile is unavailable');
        $expected=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        foreach($expected['sources']as$relative=>$sha){$file=\OC::$SERVERROOT.'/'.$relative;if(is_link($file)||!is_file($file)||filesize($file)>8388608||!hash_equals($sha,hash_file('sha256',$file)))throw new \RuntimeException('Native Home registry implementation changed');}
        return $expected;
    }
    public static function assertCurrent():void {
        if(self::$failed)throw new \RuntimeException('Native Home profile is latched');
        try{self::check();}catch(\Throwable $error){self::$failed=true;throw new \RuntimeException('Native Home producer profile changed',0,$error);}
    }
    private static function check():void {
        $expected=self::expected();$registry=$expected['registrations'];
        $dispatcher=\OCP\Server::get(\OCP\EventDispatcher\IEventDispatcher::class);if($dispatcher::class!==\OC\EventDispatcher\EventDispatcher::class)throw new \RuntimeException('Native Home event dispatcher changed');
        $native=self::property($dispatcher,'dispatcher');if($native::class!==\Symfony\Component\EventDispatcher\EventDispatcher::class)throw new \RuntimeException('Native Home event registry changed');$raw=self::property($native,'listeners');
        foreach($registry['events']as$event=>$rows){$groups=$raw[$event]??[];if(!is_array($groups)||count($groups)>64)throw new \RuntimeException('Native Home event registry changed');krsort($groups,SORT_NUMERIC);$position=0;foreach($groups as$priority=>$listeners){if(!is_int($priority)||!is_array($listeners)||!array_is_list($listeners)||count($listeners)>64)throw new \RuntimeException('Native Home event registry changed');foreach($listeners as$index=>$listener){$want=$rows[$position++]??null;if(!self::eventSlotMatches($want,$priority,$index))throw new \RuntimeException('Native Home event order changed');self::callback($listener,$want);}}if($position!==count($rows))throw new \RuntimeException('Native Home event inventory changed');}
        $hooks=(new \ReflectionProperty('OC_Hook','registered'))->getValue();foreach($registry['hooks']as$key=>$rows){[$scope,$hook]=explode('/',$key,2);$actual=$hooks[$scope][$hook]??[];if($rows!==[]||$actual!==[])throw new \RuntimeException('Native Home legacy hook inventory changed');}
        $manager=\OCP\Server::get(\OCP\Files\Mount\IMountManager::class);if($manager::class!==\OC\Files\Mount\Manager::class)throw new \RuntimeException('Native Home mount manager changed');$setup=$manager->getSetupManager();if($setup::class!==\OC\Files\SetupManager::class||self::property($setup,'setupBuiltinWrappersDone')!==true)throw new \RuntimeException('Native Home builtin registry is unavailable');
        $collection=self::property($setup,'mountProviderCollection');if($collection::class!==\OC\Files\Config\MountProviderCollection::class)throw new \RuntimeException('Native Home providers changed');
        foreach($registry['providers']as$field=>$rows){
            $actual=array_values(self::property($collection,$field));$classes=[];
            foreach($actual as$provider){if(!is_object($provider))throw new \RuntimeException('Native Home provider selector changed');$classes[]=$provider::class;}
            $orders=$registry['provider_orders'][$field]??[array_column($rows,'class')];
            // Only the two observed stock orders are accepted; pin the first observed order for this child lifetime.
            $selected=$field==='providers'?self::$providerOrder:null;
            if(!self::providerOrderMatches($rows,$classes,$orders,$selected))throw new \RuntimeException('Native Home provider selector changed');
            if($field==='providers'&&self::$providerOrder===null)self::$providerOrder=$classes;
            foreach($rows as$row){self::classSource($row['class'],$row['class_sha256']);}
        }
        $factory=self::property($setup,'storageFactory');if($factory::class!==\OC\Files\Storage\StorageFactory::class)throw new \RuntimeException('Native Home storage factory changed');$wrappers=self::property($factory,'storageWrappers');if(!is_array($wrappers)||count($wrappers)>16)throw new \RuntimeException('Native Home storage wrapper inventory changed');
        if(self::$owned!==null){$own=$wrappers[self::$owned['name']]??null;if(!is_array($own)||($own['wrapper']??null)!==self::$owned['wrapper']||($own['priority']??null)!==PHP_INT_MIN)throw new \RuntimeException('Native Home storage guard changed');unset($wrappers[self::$owned['name']]);}
        $builtinNames=array_column($registry['storage_wrappers'],'name');
        if(array_values(array_intersect(array_keys($wrappers),$builtinNames))!==$builtinNames)throw new \RuntimeException('Native Home builtin wrapper order changed');
        foreach($registry['storage_wrappers']as$row){$name=$row['name'];$actual=$wrappers[$name]??null;if(!is_array($actual)||array_keys($actual)!==['wrapper','priority']||$actual['priority']!==$row['priority'])throw new \RuntimeException('Native Home storage wrapper changed');self::callback($actual['wrapper'],$row['callback']);unset($wrappers[$name]);}
        // These two exact pinned listeners install their target-specific wrappers during BeforeFS.
        $late=['OCA\\Files_Trashbin\\Storage'=>['OCA\\Files_Trashbin\\Listener\\EventListener',1,71,81],'OCA\\FilesLock\\Storage\\LockWrapper'=>['OCA\\FilesLock\\Listeners\\BeforeFileSystemSetupListener',10,43,51]];
        foreach($wrappers as$name=>$actual){if(!isset($late[$name])||!is_array($actual)||array_keys($actual)!==['wrapper','priority']||!($actual['wrapper']??null) instanceof \Closure)throw new \RuntimeException('Native Home added storage wrapper changed');[$class,$priority,$start,$end]=$late[$name];$r=new \ReflectionFunction($actual['wrapper']);$bound=$r->getClosureThis();if($actual['priority']!==$priority||$r->getClosureScopeClass()?->getName()!==$class||!is_object($bound)||$bound::class!==$class||$r->getStaticVariables()!==[])throw new \RuntimeException('Native Home added storage wrapper binding changed');$hash=null;foreach($registry['events']['OCP\\Files\\Events\\BeforeFileSystemSetupEvent']as$listener)if($listener['class']===$class)$hash=$listener['class_sha256'];if(!is_string($hash))throw new \RuntimeException('Native Home wrapper source is unavailable');self::source($r,$hash,$start,$end);}
    }
}
