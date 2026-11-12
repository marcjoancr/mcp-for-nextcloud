<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Transmission;

/** An offset disambiguates autumn transitions; the named zone must agree, including spring gaps. */
final class ScheduleInstant {
    public static function parse(string $text,string $zone,int $now):int {
        if(!in_array($zone,\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true)||strlen($text)>32
            ||preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$text)!==1)throw new \InvalidArgumentException('Provide an explicit RFC3339 instant and its IANA timezone.');
        $canonical=str_ends_with($text,'Z')?substr($text,0,-1).'+00:00':$text;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$canonical);
        $errors=\DateTimeImmutable::getLastErrors();
        if($date===false||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))
            ||$date->format('Y-m-d\TH:i:sP')!==$canonical
            ||$date->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i:sP')!==$canonical)throw new \InvalidArgumentException('The scheduled instant and timezone do not agree.');
        $epoch=$date->getTimestamp();
        if($epoch<$now+60||$epoch>$now+366*86400)throw new \InvalidArgumentException('Schedule between one minute and366 days in the future.');
        return $epoch;
    }
}
