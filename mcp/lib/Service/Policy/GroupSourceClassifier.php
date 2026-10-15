<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Policy;

use OCP\Group\Backend\INamedBackend;
use OCP\GroupInterface;
use OCP\IGroupManager;
use Throwable;

/** Uses public backends, never a cached IGroup. Duplicate backend names deliberately count twice. */
class GroupSourceClassifier {
    public function __construct(private IGroupManager $groups) {}

    /**
     * Whether the group may grant (contracts §3.3.5): served by exactly one backend, named 'Database'.
     *
     * @throws PolicyUnavailable when a backend cannot be read
     */
    public function trusted(string $gid): bool {
        $serving = $this->serving($gid);
        return count($serving) === 1 && $serving[0] instanceof INamedBackend
            && $serving[0]->getBackendName() === 'Database';
    }

    /**
     * Whether any backend registered in this process serves the group: the fresh existence read of the lifecycle
     * job. Database::groupExists keeps a positive cache, so a long process may still see a group deleted elsewhere.
     *
     * @throws PolicyUnavailable when a backend cannot be read
     */
    public function exists(string $gid): bool {
        return $this->serving($gid) !== [];
    }

    /**
     * @return list<GroupInterface> the backends that answer groupExists($gid) with true
     * @throws PolicyUnavailable when a backend is invalid or cannot be read
     */
    private function serving(string $gid): array {
        try {
            $serving = [];
            foreach ($this->groups->getBackends() as $backend) {
                if (!$backend instanceof GroupInterface) {
                    throw new \UnexpectedValueException('Invalid group backend');
                }
                $exists = $backend->groupExists($gid);
                if (!is_bool($exists)) {
                    throw new \UnexpectedValueException('Invalid backend existence result');
                }
                if ($exists) {
                    $serving[] = $backend;
                }
            }
            return $serving;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot classify group source', previous: $e);
        }
    }
}
