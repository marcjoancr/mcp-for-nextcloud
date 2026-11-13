<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

/** Preserve native AllConfig semantics with request-scoped app/user reads. */
final class SharingConfig extends \OC\AllConfig
{
    private \OC\AppConfig $apps;
    private \OCP\Config\IUserConfig $users;
    public function __construct(FreshCore $core)
    {
        parent::__construct(\OCP\Server::get(\OC\SystemConfig::class));
        $this->apps = $core->appConfig();
        $this->users = $core->userConfig();
    }
    public function getAppValue($appName, $key, $default = '')
    {
        return $this->apps->getValue($appName, $key, $default) ?? $default;
    }
    public function getUserValue($userId, $appName, $key, $default = '')
    {
        if ($userId === null || $userId === '' || !$this->users->hasKey($userId, $appName, $key)) {
            return $default;
        }
        return $this->users->getValueMixed($userId, $appName, $key, $default ?? '');
    }
}
