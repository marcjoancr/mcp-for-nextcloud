<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
$request = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$user = Server::get(\OCP\IUserManager::class)->get($request['uid']);
switch ($request['action']) {
 case 'enabled': $user->setEnabled($request['value']); break;
 case 'membership':
  $group = Server::get(\OCP\IGroupManager::class)->get($request['group']);
  if ($request['value']) {$group->addUser($user);} else {$group->removeUser($user);}
  break;
 case 'app': Server::get(\OCP\IAppConfig::class)->setValueString($request['app'], 'enabled', $request['value']); break;
 case 'config':
  $config = Server::get(\OCP\IAppConfig::class);
  foreach ($request['values'] as $key => $value) {
   if ($value === null) {$config->deleteKey('forms', $key);}
   elseif (is_bool($value)) {$config->setValueBool('forms', $key, $value);}
   elseif (is_int($value)) {$config->setValueInt('forms', $key, $value);}
   elseif (is_array($value)) {$config->setValueArray('forms', $key, $value);}
   else {throw new RuntimeException('Unsupported fixture config type');}
  }
  break;
 default: throw new RuntimeException('Unknown principal fixture mutation');
}
echo "committed\n";
