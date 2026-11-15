<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */ declare(strict_types=1);namespace OCA\Forms\Mcp\Service\CircleDetached;final class FileUnshare extends \OCA\Circles\FederatedItems\Files\FileUnshare {private Lease $lease;public function __construct(Lease $lease,MountRequest $mount,EventService $events,Config $config){$this->lease=$lease;parent::__construct($mount,$events,$config);}public function verify(\OCA\Circles\Model\Federated\FederatedEvent $event):void {$this->lease->assertActive();parent::verify($event);}public function manage(\OCA\Circles\Model\Federated\FederatedEvent $event):void {$this->lease->assertActive();parent::manage($event);}public function result(\OCA\Circles\Model\Federated\FederatedEvent $event,array $results):void {$this->lease->assertActive();parent::result($event,$results);}/**
 * @param Throwable $t
 * @param array $serializable
 */
public function t(\Throwable $t, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param Throwable $t
 * @param int $level
 * @param array $serializable
 */
public function throwable(\Throwable $t, int $level = 3, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param Exception $e
 * @param array $serializable
 */
public function e(\Exception $e, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param Exception $e
 * @param int|array $level
 * @param array $serializable
 */
public function exception(\Exception $e, $level = 3, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function emergency(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function alert(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function warning(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function notice(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param array $serializable
 */
public function debug(string $message, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param int $level
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function log(int $level, string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @return LoggerInterface
 */
public function logger(): \Psr\Log\LoggerInterface
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 * @param string $value
 *
 * @param string $default
 *
 * @return string
 */
public function setup(string $key, string $value = '', string $default = ''): string
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 * @param array $value
 * @param array $default
 *
 * @return array
 */
public function setupArray(string $key, array $value = [], array $default = []): array
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 * @param int $value
 * @param int $default
 *
 * @return int
 */
public function setupInt(string $key, int $value = -999, int $default = 0): int
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 *
 * @return string
 */
public function appConfig(string $key): string
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
}
