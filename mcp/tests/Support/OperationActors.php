<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support;
final class OperationActors implements \OCA\Mcp\Mcp\Operations\ActorIdentity {
    public string $generation='one';
    public function capture(string $uid):string { return hash('sha256',$uid."\0".$this->generation); }
    public function assertCurrent(string $uid,string $original):void {
        if(!hash_equals($this->capture($uid),$original))throw new \OCA\Mcp\Mcp\Operations\ActorRetired();
    }
}
