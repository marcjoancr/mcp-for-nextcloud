<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
$request = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$uid = $request['uid'];
$allow = $request['allow'];
$mode = $request['mode'];
$policy = Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
if ($mode === 'target_group') {
    if (!$allow) {
        Server::get(\OCP\IGroupManager::class)->get($request['group_id'])->delete();
    }
} elseif ($mode === 'expiration') {
    $config = Server::get(\OCP\IAppConfig::class);
    $values = $allow ? $request['old_expiration'] : ['shareapi_default_internal_expire_date' => 'yes', 'shareapi_enforce_internal_expire_date' => 'yes', 'shareapi_internal_expire_after_n_days' => '2'];
    foreach ($values as $key => $value) {
        $config->setValueString('core', $key, $value);
    }
} elseif ($mode === 'exclusion') {
    $mapper = Server::get(\OCP\SystemTag\ISystemTagObjectMapper::class);
    if ($allow) {
        $mapper->unassignTags((string) $request['file_id'], 'files', [(string) $request['tag_id']]);
    } else {
        $mapper->assignTags((string) $request['file_id'], 'files', [(string) $request['tag_id']]);
    }
} elseif ($mode === 'scope') {
    $state = Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state();
    Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope($state['revision'], 'admin', $uid, 'files', $allow ? null : []);
} elseif ($mode === 'files') {
    $policy->setGrant($uid, 'files', 'share', $allow);
} elseif ($mode === 'forms') {
    $policy->setGrant($uid, 'forms', 'share', $allow);
} elseif ($mode === 'connection') {
    $policy->setConnected($uid, $allow);
} elseif ($mode === 'native') {
    Server::get(\OCP\IUserManager::class)->get($uid)->setEnabled($allow);
} elseif ($mode === 'incarnation' && !$allow) {
    Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class)->runInternal([$uid], fn() => null, function () use ($uid): bool {
        Server::get(\OCA\Mcp\Mcp\Operations\DurableActorIdentity::class)->rotateBeforeCreation($uid);
        return true;
    });
}
echo "authority committed\n";
