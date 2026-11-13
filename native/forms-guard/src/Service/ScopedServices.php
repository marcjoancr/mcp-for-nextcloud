<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
use OCP\Server;
/** Fresh native actor-dependent services; dependency state never escapes into the global container. */
final class ScopedServices {
    private array $services=[];
    private FreshCore $core;
    private \OCP\IUser $user;
    private \Psr\Container\ContainerInterface $container;
    public function __construct(string $uid, private bool $deferred = false) {
        $this->core = new FreshCore($uid);
        $this->core->assertPrincipal();
        $this->user = $this->core->user();
        $this->container=(new \OCA\Forms\AppInfo\Application())->getContainer();
    }
    public function get(string $class): object {
        if (isset($this->services[$class])) {return $this->services[$class];}
        if ($class === \OCP\IGroupManager::class) {return $this->core->groups();}
        if ($class === \OCP\IAppConfig::class) {return $this->core->appConfig();}
        if ($class === \OCP\Config\IUserConfig::class) {return $this->core->userConfig();}
        if ($class === \OCP\App\IAppManager::class) {return $this->core->apps();}
        if ($class === \OCP\AppFramework\Services\IAppConfig::class) {
            return $this->services[$class] = new \OC\AppFramework\Services\AppConfig(Server::get(\OCP\IConfig::class), $this->core->appConfig(), 'forms');
        }
        if ($class===\OCP\IUserSession::class) {return $this->services[$class]=new ScopedUserSession($this->user);}
        if ($class === \OCA\Forms\Service\CirclesService::class) {
            if ($this->deferred) {
                return $this->services[$class] = new ScopedCircles();
            }
            if (Server::get(\OCP\IUserSession::class)->getUser()?->getUID() !== $this->user->getUID()) {
                throw new \RuntimeException('Immediate Circle access requires the original native session');
            }
            return $this->services[$class] = new \OCA\Forms\Service\CirclesService(
                $this->core->apps(), $this->container, Server::get(\Psr\Log\LoggerInterface::class));
        }
        if (!str_starts_with($class,'OCA\\Forms\\Service\\') && !str_starts_with($class,'OCA\\Forms\\Controller\\') && $class!==\OCA\Forms\Activity\ActivityManager::class) {return $this->container->get($class);}
        $reflection=new \ReflectionClass($class);$args=[];
        foreach ($reflection->getConstructor()?->getParameters()??[] as $parameter) {
            $type=$parameter->getType();
            if ($parameter->getName()==='appName') {$args[]='forms';}
            elseif ($parameter->getName()==='userId') {$args[]=$this->user->getUID();}
            elseif ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {$args[]=$this->get($type->getName());}
            elseif ($parameter->isDefaultValueAvailable()) {$args[]=$parameter->getDefaultValue();}
            else {throw new \RuntimeException('Unknown scoped native dependency');}
        }
        return $this->services[$class]=$reflection->newInstanceArgs($args);
    }
    public function forms(): \OCA\Forms\Service\FormsService {return $this->get(\OCA\Forms\Service\FormsService::class);}
}
