<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Private native DateTimeZone dependency, never installed as the global ISession. */
final class TransferTimeZoneSession implements \OCP\ISession {
    public function __construct(private readonly string $original,private readonly \Closure $frame) {}
    public function get(string $key) {($this->frame)();if($key==='user_id')return $this->original;if($key==='timezone')return null;throw new \LogicException('Unexpected transfer timezone session read');}
    public function exists(string $key):bool {($this->frame)();if($key==='user_id')return true;if($key==='timezone')return false;throw new \LogicException('Unexpected transfer timezone session read');}
    public function set(string $key,$value) {throw new \LogicException('Transfer timezone session writes are forbidden');}
    public function remove(string $key) {throw new \LogicException('Transfer timezone session writes are forbidden');}
    public function clear() {throw new \LogicException('Transfer timezone session writes are forbidden');}
    public function reopen():bool {throw new \LogicException('Transfer timezone session writes are forbidden');}
    public function close() {throw new \LogicException('Transfer timezone session writes are forbidden');}
    public function regenerateId(bool $deleteOldSession=true,bool $updateToken=false) {throw new \LogicException('Transfer timezone session writes are forbidden');}
    public function getId():string {throw new \LogicException('Transfer timezone cookie access is forbidden');}
}
