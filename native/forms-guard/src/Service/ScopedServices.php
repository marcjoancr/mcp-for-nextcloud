<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
use OCP\Server;
/** Fresh native actor-dependent services; dependency state never escapes into the global container. */
final class ScopedServices {
    private array $services=[];
    private \OCP\IUser $user;
    private \Psr\Container\ContainerInterface $container;
    public function __construct(string $uid) {
        $this->user=Server::get(\OCP\IUserManager::class)->get($uid)??throw new \RuntimeException('Original Forms actor is unavailable');
        if (!$this->user->isEnabled()) {throw new \RuntimeException('Original Forms actor is unavailable');}
        $this->container=(new \OCA\Forms\AppInfo\Application())->getContainer();
    }
    public function get(string $class): object {
        if (isset($this->services[$class])) {return $this->services[$class];}
        if ($class===\OCP\IUserSession::class) {return $this->services[$class]=new ScopedUserSession($this->user);}
        if ($class===\OCA\Forms\Service\CirclesService::class) {return $this->services[$class]=new ScopedCircles();}
        if (!str_starts_with($class,'OCA\\Forms\\Service\\') && $class!==\OCA\Forms\Activity\ActivityManager::class) {return $this->container->get($class);}
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
