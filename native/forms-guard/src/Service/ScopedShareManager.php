<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCP\Server;
/** Clone the pinned mutable graph without re-registering global native event hooks. */
final class ScopedShareManager
{
    private static function set(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
    public static function createdHere(\OC\Share20\Manager $manager, string $id): bool
    {
        $factory = (new \ReflectionProperty(\OC\Share20\Manager::class, 'factory'))->getValue($manager);
        return $factory instanceof SharingProviderFactory && $factory->createdHere($id);
    }
    private static function transferProvider(TransferShareManager $manager): SharingDefaultProvider {
        $factory=(new \ReflectionProperty(\OC\Share20\Manager::class,'factory'))->getValue($manager);$provider=$factory->getProviderForType(0);if(!$provider instanceof SharingDefaultProvider){throw new \RuntimeException('Unqualified transfer provider');}return $provider;
    }
    public static function circleProvider(\OC\Share20\Manager $manager):CircleBoundaryProvider {
        $factory=(new \ReflectionProperty(\OC\Share20\Manager::class,'factory'))->getValue($manager);$provider=$factory->getProviderForType(7);
        if(!$provider instanceof CircleBoundaryProvider){throw new \RuntimeException('Unqualified Circle transfer provider');}return$provider;
    }
    public static function quarantineTransfer(TransferShareManager $manager):void {self::transferProvider($manager)->quarantineTransferCreates();}
    public static function activateTransfer(TransferShareManager $manager,\OCP\Share\IShare $share):void {$manager->validateActivation($share);self::transferProvider($manager)->activateTransfer($share);}
    public static function activateTransferRecipients(TransferShareManager $manager,\OCP\Share\IShare $share,array $rows,callable $authorize):void {self::transferProvider($manager)->activateTransferRecipients($share,$rows,$authorize);}
    public static function preserveTransferRecipients(\OC\Share20\Manager $manager,\OCP\Share\IShare $share,array $rows):array {
        if(!$manager instanceof TransferShareManager){throw new \RuntimeException('Recipient-state migration requires qualified transfer authority');}
        $factory=(new \ReflectionProperty(\OC\Share20\Manager::class,'factory'))->getValue($manager);
        $provider=$factory->getProviderForType($share->getShareType());
        if(!$provider instanceof SharingDefaultProvider){throw new \RuntimeException('Unqualified transfer provider');}
        return $provider->preserveTransferRecipients($share,$rows);
    }
    public static function create(string $uid, \Closure $beforeWrite, ?string $circleIntentKey = null,?\Closure $circleOneShotProof=null,?\OCA\Forms\Mcp\Service\CircleDetached\ShareWriteBoundary $circleWriteCapability=null): \OC\Share20\Manager
    {
        $manager = clone SharingIdentity::manager();
        $core = new FreshCore($uid);
        $config = new SharingConfig($core);
        $users = Server::get(\OCP\IUserManager::class);
        if ($users::class !== \OC\User\Manager::class) {
            throw new \RuntimeException('Native sharing user manager is unqualified');
        }
        $users = clone $users;
        $backends = [];
        foreach ($users->getBackends() as $key => $backend) {
            if ($backend::class !== \OC\User\Database::class || (new \ReflectionProperty($backend, 'table'))->getValue($backend) !== 'users') {
                throw new \RuntimeException('Native sharing user backend is unqualified');
            }
            $backend = clone $backend;
            self::set($backend, 'cache', new \OCP\Cache\CappedMemoryCache());
            $backends[$key] = $backend;
        }
        self::set($users, 'backends', $backends);
        self::set($users, 'cachedUsers', [$uid => $core->user()]);
        self::set($users, 'config', $config);
        $groups = $core->groups();
        self::set($groups, 'userManager', $users);
        $provider = Server::get(\OC\Share20\DefaultShareProvider::class);
        if ($provider::class !== \OC\Share20\DefaultShareProvider::class) {
            throw new \RuntimeException('Native sharing default provider is unqualified');
        }
        $arguments = [];
        foreach ((new \ReflectionClass(\OC\Share20\DefaultShareProvider::class))->getConstructor()->getParameters() as $parameter) {
            $arguments[] = match ($parameter->getName()) {
                'config' => $config,
                'groupManager' => $groups,
                'userManager' => $users,
                'shareManager' => $manager,
                default => (new \ReflectionProperty($provider, $parameter->getName()))->getValue($provider),
            };
        }
        $provider = new SharingDefaultProvider(...$arguments);
        $provider->setWriteBoundary($beforeWrite);
        if($circleIntentKey!==null&&$circleOneShotProof!==null){throw new \RuntimeException('Circle provider intent capability is malformed');}
        $circles = $circleIntentKey===null&&$circleOneShotProof===null&&$circleWriteCapability===null ? null : static fn():CircleBoundaryProvider => CircleRuntime::provider($uid,$beforeWrite,$circleIntentKey,$circleOneShotProof,$circleWriteCapability);
        $factory = new SharingProviderFactory($core->apps(), $provider, $circles);
        foreach (['config' => $config, 'appConfig' => $core->appConfig(), 'groupManager' => $groups, 'userManager' => $users, 'factory' => $factory, 'userSession' => new ScopedUserSession($core->user())] as $field => $value) {
            self::set($manager, $field, $value);
        }
        return $manager;
    }
}
