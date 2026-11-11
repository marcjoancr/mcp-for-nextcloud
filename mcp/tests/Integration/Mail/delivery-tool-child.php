<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use OCA\Mcp\Tests\Integration\Mail\DeliveryFixtureBoundary;
use OCA\Mcp\Modules\Mail\Transmission\DeliveryWorker;
use OCP\Server;
$apps=Server::get(\OCP\App\IAppManager::class);$apps->loadApp('mail');
pcntl_async_signals(true);pcntl_signal(SIGALRM,static function():void{exit(124);});pcntl_alarm(20);
try{
    DeliveryFixtureBoundary::install();
    $raw=stream_get_contents(STDIN,65537);$ticket=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    $result=strlen($raw)<=65536&&is_array($ticket)?Server::get(DeliveryWorker::class)->run($ticket):['status'=>'refused'];
}catch(\Throwable){$result=['status'=>'unknown'];}
finally{pcntl_alarm(0);}
echo json_encode($result,JSON_THROW_ON_ERROR);
