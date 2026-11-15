<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class ConfigService
 *
 * @package OCA\Circles\Service
 */
final class Config extends \OCA\Circles\Service\ConfigService
{
    public function __construct(private Lease $lease, private array $data, private string $keyhole)
    {
    }
    public function getLoopbackInstance(): string
    {
        $this->lease->assertActive();
        return $this->data['local_instance'];
    }
    public function getFrontalInstance(): string
    {
        $this->lease->assertActive();
        return $this->data['frontal_instance'];
    }
    public function getInternalInstance(): string
    {
        $this->lease->assertActive();
        return $this->data['internal_instance'];
    }
    public function getTrustedDomains(): array
    {
        $this->lease->assertActive();
        return $this->data['trusted_domains'];
    }
    public function getAppValue(string $key): string
    {
        $this->lease->assertActive();
        if ($key !== self::KEYHOLE_CFG_REQUEST) {
            throw new \RuntimeException('Circle config key is not qualified');
        }
        return $this->keyhole;
    }
    public function getAppValueInt(string $key): int
    {
        return (int) $this->getAppValue($key);
    }
    public function getAppValueBool(string $key): bool
    {
        return $this->getAppValueInt($key) === 1;
    }
    /**
     * Set a value by key
     *
     * @param string $key
     * @param string $value
     *
     * @return void
     */
    public function setAppValue(string $key, string $value): void
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     *
     */
    public function unsetAppConfig(): void
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * Get available hosts
     *
     * @return array
     */
    public function getAvailableHosts(): array
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * Get a user value by key and user
     *
     *
     * @param string $userId
     * @param string $key
     *
     * @param string $default
     *
     * @return string
     */
    public function getCoreValueForUser($userId, $key, $default = '')
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @return bool
     * @deprecated
     */
    public function isContactsBackend(): bool
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @return int
     * @deprecated
     */
    public function contactsBackendType(): int
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * true if:
     *   - password is generated randomly
     *
     * @param Circle $circle
     *
     * @return bool
     */
    public function sendPasswordByMail(\OCA\Circles\Model\Circle $circle): bool
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * true if:
     * - password enforced for Circle
     * - single password enabled for Circle
     * - single password defined within Circle's settings
     *
     * @param Circle $circle
     *
     * @return bool
     */
    public function isSinglePasswordAvailable(\OCA\Circles\Model\Circle $circle): bool
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * true if:
     *   - global setting of Nextcloud enforce password on shares.
     *   - setting of Circles' app enforce password on shares.
     *   - setting for specific Circle enforce password on shares.
     *
     * @param Circle $circle
     *
     * @return bool
     */
    public function enforcePasswordOnSharedFile(\OCA\Circles\Model\Circle $circle): bool
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * // TODO: fetch data from somewhere else than hard coded...
     *
     * @return array
     */
    public function getSettings(): array
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @return bool
     */
    public function isGSAvailable(): bool
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @return string
     * @throws GSStatusException
     */
    public function getGSLookup(): string
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @return array
     */
    public function getGSSMockup(): array
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @param string $type
     *
     * @return string
     */
    public function getGSInfo(string $type): string
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @return array
     * @throws GSStatusException
     */
    public function getGSData(): array
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * returns loopback address based on getLoopbackInstance and LOOPBACK_CLOUD_SCHEME
     * should be used to async process
     *
     * @param string $route
     * @param array $args
     *
     * @return string
     */
    public function getLoopbackPath(string $route = '', array $args = []): string
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @param int $iface
     *
     * @return string
     */
    public function getIfaceInstance(int $iface): string
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @param string $instance
     *
     * @return bool
     */
    public function isLocalInstance(string $instance): bool
    {
        if ($instance === '') {
            return true;
        }
        $instance = strtolower($instance);
        if ($instance === strtolower($this->getInternalInstance())) {
            return true;
        }
        if ($instance === strtolower($this->getFrontalInstance())) {
            return true;
        }
        if ($instance === strtolower($this->getLoopbackInstance())) {
            return true;
        }
        return in_array($instance, $this->getTrustedDomains());
    }
    /**
     * @param IFederatedUser $federatedUser
     * @param bool $displayName
     * @param bool $displayInstance
     *
     * @return string
     */
    public function displayFederatedUser(\OCA\Circles\IFederatedUser $federatedUser, bool $displayName = false, bool $displayInstance = true): string
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @param string $instance
     * @param int $type
     *
     * @return string
     */
    public function displayInstance(string $instance, int $type = self::DISPLAY_NONE): string
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * - Create route using getLoopbackAddress()
     * - perfect for loopback request.
     *
     * @param NCRequest $request
     * @param string $route
     * @param array $args
     */
    public function configureLoopbackRequest(\OCA\Circles\Tools\Model\NCRequest $request, string $route = '', array $args = []): void
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @param NCRequest $request
     */
    public function configureRequest(\OCA\Circles\Tools\Model\NCRequest $request): void
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * @param string $route
     * @param array $args
     *
     * @return string
     */
    public function linkToRoute(string $route, array $args): string
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    /**
     * Enforce or Block circle's config/type
     *
     * @param Circle $circle
     */
    public function confirmAllowedCircleTypes(\OCA\Circles\Model\Circle $circle): void
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
    public function isFederatedTeamsEnabled(): bool
    {
        throw new \RuntimeException("Circle config method is not qualified");
    }
/**
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
