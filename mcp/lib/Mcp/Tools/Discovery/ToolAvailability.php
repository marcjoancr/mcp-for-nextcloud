<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools\Discovery;

use OCA\Mcp\Access\Grants\GrantMatrix;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use InvalidArgumentException;
use OCP\App\AppPathNotFoundException;
use OCP\IUser;
use OCA\Mcp\Access\Policy\Resolution\GrantUnknown;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\RestrictedModule;
use OCA\Mcp\Access\AppAvailability\AppEnablement;

/** Rechecks role, grants and optional app availability for the current user. */
final class ToolAvailability {
    public function __construct(
        private \OCA\Mcp\Access\Grants\GrantPolicy $policy,
        private \OCP\App\IAppManager $appManager,
        private \OCP\IUserManager $userManager,
        private ?GuestRestrictions $guests = null,
        private ?ConnectionContext $connection = null,
    ) {}

    /**
     * The role of a {@see RestrictedModule} comes before everything else: outside it the module does not exist.
     *
     * @param ToolModule $module module whose tools are about to be listed or called
     * @param string $userId authenticated user
     */
    public function permits(ToolModule $module, string $userId): bool {
        return !$module instanceof RestrictedModule || $module->permits($userId);
    }

    /** App IDs required by the module and tool, including those checked against a guest's allowlist.
     * @param array{module:string, app?:string} $definition
     * @return list<string>
     */
    public static function requiredApps(array $definition): array {
        $module = $definition['module'];
        $moduleApp = GrantMatrix::MODULE_APPS[$module] ?? $module;
        $apps = $moduleApp === '' ? [] : [$moduleApp];
        $toolApp = $definition['app'] ?? '';
        if ($toolApp !== '' && $toolApp !== $moduleApp) {
            $apps[] = $toolApp;
        }
        return $apps;
    }

    /** Group administration uses instance availability; user administration and MCP use that user's enabled apps. */
    public function appEnabled(string $appId, ?IUser $user): bool {
        if ($appId === 'files') {
            return true; // Core Files is always enabled.
        }
        try {
            $enabled = $user === null ? AppEnablement::forAnyone($this->appManager, $appId)
                : AppEnablement::forUser($this->appManager, $appId, $user);
            if (!$enabled) {
                return false;
            }
            // Enabled settings can outlive the installed app files.
            $this->appManager->getAppPath($appId);
            return true;
        } catch (AppPathNotFoundException) {
            return false;
        }
    }

    /**
     * The grant decides first: the tool's operation, or any of the alternatives it lists in `grantAnyOf` when the kind
     * of write depends on the arguments (files_share shares with a person under `share` and creates a public link
     * under `link`). A tool listing alternatives must check the one each call needs itself, before planning and again
     * before writing; the registry only lets the call in.
     *
     * @param array{name:string, module:string, operation:string, grantAnyOf?:list<string>, grantAllOf?:array<string,list<string>>, app?:string} $definition
     */
    public function allowed(array $definition, string $userId): bool {
        return ($this->connection === null || $this->connection->allows($definition['name']))
            && $this->policyAllows($definition, $userId, true);
    }

    public function administrativelyAllowed(array $definition, string $userId): bool {
        return $this->policyAllows($definition, $userId, false);
    }

    private function policyAllows(array $definition, string $userId, bool $forConnection): bool {
        try {
            // Three-valued "any of": a known Allow wins over an unknown alternative, whatever their order.
            $granted = $this->primaryGranted($definition, $userId, $forConnection);
            if (!$granted) { return false; }
            foreach ($definition['grantAllOf'] ?? [] as $module => $operations) {
                foreach ($operations as $operation) {
                    if (!$this->policy->granted($userId, $module, $operation)) { return false; }
                }
            }
        } catch (InvalidArgumentException) {
            return false;
        }
        $user = null;
        foreach (self::requiredApps($definition) as $app) {
            if ($this->guests !== null && !$this->guests->allowsApp($userId, $app)) {
                return false;
            }
            if ($app === 'files') {
                continue;
            }
            $user ??= $this->userManager->get($userId);
            if ($user === null || !$this->appEnabled($app, $user)) {
                return false;
            }
        }
        return true;
    }

    private function primaryGranted(array $definition, string $uid, bool $forConnection): bool {
        $module = $definition['module'];
        $name = $definition['name'];
        if (!isset($definition['grantAnyOf'])) {
            return $this->policy->toolGranted($uid, $name, $module, $definition['operation']);
        }
        return $this->permittedVariants($definition, $uid, $forConnection) !== [];
    }

    public function permittedVariants(array $definition, string $uid, bool $forConnection = false): array {
        $module = $definition['module'];
        $name = $definition['name'];
        $permitted = [];
        $unknown = null;
        foreach ($definition['grantAnyOf'] ?? [] as $op) {
            if ($forConnection && $this->connection !== null && !$this->connection->allowsVariant($name, $op)) {
                continue;
            }
            try {
                if ($this->policy->granted($uid, $module, $op) && $this->policy->toolGranted($uid, $name, $module, $op)) {
                    $permitted[] = $op;
                }
            } catch (GrantUnknown $e) {
                $unknown = $e;
            }
        }
        if ($unknown !== null && $permitted === []) {
            throw $unknown;
        }
        return $permitted;
    }

    /** Operation checks inside a tool share the same rules as discovery. Variant tools keep their argument-specific checks. */
    public function withinTool(string $uid, array $definition, \Closure $work): mixed {
        return isset($definition['grantAnyOf']) ? $work()
            : $this->policy->withinTool($uid, $definition['name'], $definition['module'], $definition['operation'], $work);
    }
}
