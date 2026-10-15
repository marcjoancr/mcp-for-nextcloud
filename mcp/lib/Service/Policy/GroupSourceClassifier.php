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

    public function trusted(string $gid): bool {
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
            return count($serving) === 1 && $serving[0] instanceof INamedBackend
                && $serving[0]->getBackendName() === 'Database';
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot classify group source', previous: $e);
        }
    }
}
