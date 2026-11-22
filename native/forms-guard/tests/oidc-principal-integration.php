<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Run against a qualified Nextcloud + user_oidc installation. All fixture rows roll back.
define('OC_CONSOLE', true);
require (getenv('MCP_FORMS_CORE_ROOT') ?: '/var/www/html').'/lib/base.php';
$apps = \OCP\Server::get(\OCP\App\IAppManager::class);
\OC_App::loadApps();
$apps->loadApp('mcp');$apps->loadApp('forms');
if ($candidate = getenv('MCP_FORMS_PRINCIPAL_CANDIDATE')) {
    require $candidate.'/Service/FreshUserBackend.php';require $candidate.'/Service/FreshGuestGroup.php';require $candidate.'/Service/FreshCore.php';
}
$check = static function(bool $ok, string $message): void {if (!$ok) {throw new RuntimeException($message);}};
$refuses = static function(Closure $call, string $message) use ($check): void {
    try {$call();} catch (RuntimeException $e) {$check($e->getMessage() === $message, 'Unexpected refusal: '.$e->getMessage());return;}
    throw new RuntimeException('Missing refusal: '.$message);
};
$users = \OCP\Server::get(\OCP\IUserManager::class);
$backend = null;
foreach ($users->getBackends() as $b) {if ($b::class === 'OCA\\UserOIDC\\User\\Backend') {$backend=$b;break;}}
$check($backend !== null, 'Native OIDC backend unavailable');
$db = \OCP\Server::get(\OCP\IDBConnection::class);
$uid = 'forms-oidc-check-'.bin2hex(random_bytes(8));
$db->beginTransaction();
try {
    $db->executeStatement('INSERT INTO *PREFIX*user_oidc (user_id, display_name) VALUES (?, ?)', [$uid, 'Forms regression fixture']);
    $original = $users->get($uid);$check($original !== null && $original->isEnabled(), 'Fixture not enabled');
    (new \OCA\Forms\Mcp\Service\FreshCore($uid))->assertPrincipal();
    $refuses(static fn() => (new \OCA\Forms\Mcp\Service\FreshCore($uid))->assertPrincipal(true), 'Administrator required');
    $db->executeStatement('INSERT INTO *PREFIX*group_user (gid, uid) VALUES (?, ?)', ['admin', $uid]);
    (new \OCA\Forms\Mcp\Service\FreshCore($uid))->assertPrincipal(true);
    $db->executeStatement('DELETE FROM *PREFIX*group_user WHERE gid = ? AND uid = ?', ['admin', $uid]);
    $refuses(static fn() => (new \OCA\Forms\Mcp\Service\FreshCore($uid))->assertPrincipal(true), 'Administrator required');
    $db->executeStatement('INSERT INTO *PREFIX*preferences (userid, appid, configkey, configvalue) VALUES (?, ?, ?, ?)', [$uid, 'core', 'enabled', 'false']);
    $refuses(static fn() => new \OCA\Forms\Mcp\Service\FreshCore($uid), 'Forms principal is disabled');
    $check($original->isEnabled(), 'Original enabled cache was changed');
    $db->executeStatement('DELETE FROM *PREFIX*preferences WHERE userid = ?', [$uid]);
    (new \OCA\Forms\Mcp\Service\FreshCore($uid))->assertPrincipal();
    foreach (\OCP\Server::get(\OCP\IGroupManager::class)->getBackends() as $groupBackend) {
        if ($groupBackend::class !== 'OCA\\Guests\\GroupBackend') {continue;}
        $manager = (new ReflectionProperty($groupBackend, 'guestManager'))->getValue($groupBackend);
        $guests = (new ReflectionProperty($manager, 'userBackend'))->getValue($manager);
        $check(!$guests->userExists($uid), 'Guest absence not warmed');
        $db->executeStatement('INSERT INTO *PREFIX*guests_users (uid, uid_lower, password) VALUES (?, ?, ?)', [$uid, mb_strtolower($uid), '']);
        $refuses(static fn() => new \OCA\Forms\Mcp\Service\FreshCore($uid), 'Forms guest authority is unqualified');
        $check(!$guests->userExists($uid), 'Original Guest cache was changed');
        $db->executeStatement('DELETE FROM *PREFIX*guests_users WHERE uid = ?', [$uid]);
        (new \OCA\Forms\Mcp\Service\FreshCore($uid))->assertPrincipal();
    }
    $check($backend->userExists($uid), 'OIDC cache not warmed');
    $db->executeStatement('DELETE FROM *PREFIX*user_oidc WHERE user_id = ?', [$uid]);
    $refuses(static fn() => new \OCA\Forms\Mcp\Service\FreshCore($uid), 'Forms principal is unavailable');
    $check($backend->userExists($uid), 'Original OIDC mapper cache was changed');
    echo "OIDC principal: existence, disable, admin grant/revocation and cache isolation verified\n";
} finally {$db->rollBack();}
$check($db->executeQuery('SELECT user_id FROM *PREFIX*user_oidc WHERE user_id = ?', [$uid])->fetchOne() === false, 'Fixture rollback failed');
