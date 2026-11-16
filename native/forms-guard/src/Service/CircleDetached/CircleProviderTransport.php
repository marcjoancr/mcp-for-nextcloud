<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/** Native service type adapter for the detached local, one-shot event transport. */
final class CircleProviderTransport extends \OCA\Circles\Service\FederatedEventService {
    private LocalTransport $transport;
    private function __construct() {}
    public static function wrap(LocalTransport $transport):self {
        $reflection=new \ReflectionClass(self::class);$wrapper=$reflection->newInstanceWithoutConstructor();$wrapper->transport=$transport;return$wrapper;
    }
    public function newEvent(\OCA\Circles\Model\Federated\FederatedEvent $event):array {
        $result=$this->transport->newEvent($event);$this->transport->drain();return$result;
    }
    public function initBroadcast(\OCA\Circles\Model\Federated\FederatedEvent $event):bool {throw new \RuntimeException('Circle broadcast transport is unavailable');}
    public function manageResults(string $token):void {throw new \RuntimeException('Persisted Circle event replay is unavailable');}
    public function close():void {$this->transport->close();}
}
