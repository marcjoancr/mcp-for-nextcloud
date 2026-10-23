<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Deck;
trait CapabilityNativeFixtures {
    private function capabilityNative(string $class, array $services, ?\OCP\ISession $session = null): object {
        $session ??= $this->createMock(\OCP\ISession::class);
        if (func_num_args() < 3) { $session->method('get')->willReturn('alice'); }
        $caller = new \OCA\Mcp\Modules\Deck\Access\DeckCaller(fn()=> $services[\OCA\Deck\Service\BoardService::class], $session);
        $references = new \OCA\Mcp\Modules\Deck\Reading\CardReferenceFilter($caller,
            fn()=> $services[\OCA\Deck\Db\CardMapper::class], fn()=> $services[\OCA\Deck\Service\PermissionService::class], fn()=> $services[\OCA\Deck\Db\StackMapper::class]);
        $args = [$caller];
        foreach ((new \ReflectionClass($class))->getConstructor()->getParameters() as $p) {
            $name = $p->getName();
            if ($name === 'caller') { continue; }
            if ($name === 'references') { $args[]=$references; continue; }
            if ($name === 'users') { $args[]=$services[\OCP\IUserManager::class]; continue; }
            $member=ucfirst(substr($name,0,-8));
            $ns=str_ends_with($member,'Service')?'OCA\\Deck\\Service\\':($member==='DeckShareProvider'?'OCA\\Deck\\Sharing\\':'OCA\\Deck\\Db\\');
            $args[]=fn()=> $services[$ns.$member];
        }
        return new $class(...$args);
    }
}
