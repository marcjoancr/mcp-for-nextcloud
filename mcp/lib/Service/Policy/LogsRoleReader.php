<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Service\Policy;

use OCA\Mcp\Service\LogsAccess;
use OCP\GroupInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Security\Ip\IRemoteAddress;

/**
 * Fresh equivalent of the logs role gate; the existing LogsAccess consumer stays unchanged until ticket 09.
 *
 * One intentional divergence: group ids compare strictly. IGroupManager::isInGroup compares loosely on
 * NC32–34 ('01' == '1') and strictly on NC35. LogsGroupsMapper already treats "1" and "01" as distinct
 * groups, so the strict comparison is the fail-closed reading of the listed role on every version.
 */
class LogsRoleReader {
    public function __construct(
        private LogsAccess $logs,
        private IGroupManager $groups,
        private IRemoteAddress $remote,
        private IUserSession $session,
    ) {}

    /**
     * @param list<string> $groupIds fresh membership, including any delivered event overlay
     * @return array{available:bool,permitted:bool}
     */
    public function read(?string $uid, array $groupIds): array {
        $available = $this->logs->available();
        if (!$available || $uid === null) {
            return ['available' => $available, 'permitted' => false];
        }
        // Match IGroupManager::isAdmin (NC32–35): the remote-address restriction denies native administration
        // only when the uid asked about is the session user; any other uid keeps its administrator role.
        if ($this->remote->allowsAdminActions() || $uid !== $this->session->getUser()?->getUID()) {
            foreach ($this->groups->getBackends() as $backend) {
                if ($backend->implementsActions(GroupInterface::IS_ADMIN) && $backend->isAdmin($uid)) {
                    return ['available' => true, 'permitted' => true];
                }
            }
            if (in_array('admin', $groupIds, true)) {
                return ['available' => true, 'permitted' => true];
            }
        }
        foreach ($this->logs->groups() as $gid) {
            if (in_array($gid, $groupIds, true)) {
                return ['available' => true, 'permitted' => true];
            }
        }
        return ['available' => true, 'permitted' => false];
    }
}
