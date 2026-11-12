<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Operations;
/** Only a proven pre-effect refusal may release a reserved write without an uncertain native result. */
class BeforeEffectFailure extends \OCA\Mcp\Mcp\Tools\Results\ToolFailure {
    /** Use only around read-only validation, before any native write or workflow transition. */
    public static function preflight(\Closure $review): mixed {
        try { return $review(); }
        catch (\OCA\Mcp\Mcp\Confirmation\PlanChanged $e) { throw $e; }
        catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure|\InvalidArgumentException $e) { throw new self($e->getMessage()); }
        catch (\Throwable) { throw new self('Mail validation failed before any write was attempted.'); }
    }
}
