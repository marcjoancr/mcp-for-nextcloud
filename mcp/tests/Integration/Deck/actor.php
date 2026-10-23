<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Integration\Deck\DeckFixture as F;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCP\Server;

require dirname(__DIR__) . '/bootstrap.php';
[$script, $uid, $action] = $argv;
if (!in_array($uid, [F::OWNER, F::EDITOR], true)) {
    throw new RuntimeException('Not a disposable Deck fixture actor');
}
Server::get(OCP\IUserSession::class)->setUser(Server::get(OCP\IUserManager::class)->get($uid));
Server::get(OCP\ISession::class)->set('user_id', $uid);
OC_Util::setupFS($uid);
$apps = Server::get(OCP\App\IAppManager::class);
if (!$apps->isEnabledForAnyone('deck')) {
    throw new RuntimeException('Deck must be provisioned; this scenario never skips');
}
$apps->loadApp('deck');
$boards = Server::get(BoardService::class);
$boards->setUserId($uid);
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(OCA\Mcp\Tests\Integration\Deck\CardDueDateFixture::run($uid, $action, $in), JSON_THROW_ON_ERROR);
