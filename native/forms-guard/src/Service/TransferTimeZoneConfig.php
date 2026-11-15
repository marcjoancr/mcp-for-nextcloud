<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Exact two native timezone inputs, read freshly under the original transfer frame. */
final class TransferTimeZoneConfig extends \OC\AllConfig {
    public function __construct(private readonly string $original,private readonly string $userZone,private readonly string $defaultZone) {
        // An invalid user preference makes native DateTimeZone log. Refuse this profile before that effect.
        if ($userZone!=='') {try {new \DateTimeZone($userZone);}catch(\Exception){throw new \RuntimeException('Transfer timezone preference is unqualified');}}
    }
    public function getUserValue($userId,$appName,$key,$default='') {
        if ($userId!==$this->original || $appName!=='core' || $key!=='timezone') {throw new \LogicException('Unexpected transfer timezone config read');}
        return $this->userZone;
    }
    public function getSystemValueString(string $key,string $default=''):string {
        if ($key!=='default_timezone') {throw new \LogicException('Unexpected transfer timezone config read');}
        return $this->defaultZone;
    }
}
