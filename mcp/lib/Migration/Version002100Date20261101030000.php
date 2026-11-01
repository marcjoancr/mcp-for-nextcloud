<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\LegacyToolCatalog;
use OCA\Mcp\Access\Policy\Resolution\GrantUnknown;
use OCA\Mcp\Db\PolicyStateMapper;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Refuse activation if the new service or tool resolver disagrees with the legacy policy. */
class Version002100Date20261101030000 extends SimpleMigrationStep {
    public function __construct(private GrantPolicy $policy, private IUserManager $users, private PolicyStateMapper $state) {}

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $revision = $this->state->state()['revision'];
        $this->users->callForAllUsers(function (IUser $user): void {
            $uid = $user->getUID();
            $this->policy->within($uid, function () use ($uid): void {
                $this->verify(fn () => $this->policy->legacyEligible($uid), fn () => $this->policy->eligible($uid));
                foreach (LegacyToolCatalog::DEFINITIONS as $name => $definition) {
                    $module = $definition['module'];
                    foreach ($definition['grantAnyOf'] ?? [$definition['operation']] as $operation) {
                        $this->verify(fn () => $this->policy->granted($uid, $module, $operation),
                            fn () => $this->policy->toolGranted($uid, $name, $module, $operation));
                    }
                }
            });
        });
        if ($this->state->state()['revision'] !== $revision) {
            throw new \RuntimeException('MCP policy changed during verification. Retry the upgrade.');
        }
        $output->info('Verified migrated MCP service and tool permissions against the 2.0.0 catalog.');
    }

    private function verify(Closure $old, Closure $new): void {
        if ($this->decision($old) !== $this->decision($new)) {
            throw new \RuntimeException('Migrated MCP permissions differ from the previous authorization. Activation stopped.');
        }
    }

    private function decision(Closure $read): ?bool {
        try {
            return $read();
        } catch (GrantUnknown) {
            return null;
        }
    }
}
