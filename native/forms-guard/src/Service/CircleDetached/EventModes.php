<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
final class EventModes {
    public static function incoming(bool $forceSync,bool $async,bool $dataOnly,bool $limited):void {
        if($forceSync||$async||$dataOnly||$limited){throw new \RuntimeException('Circle incoming event mode is not qualified');}
    }
    public static function configured(bool $forceSync,bool $async,bool $dataOnly,bool $limited):void {
        if($forceSync||!$async||$dataOnly||$limited){throw new \RuntimeException('Native Circle async event flags changed');}
    }
}
