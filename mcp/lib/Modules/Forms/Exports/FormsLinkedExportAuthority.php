<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Exports;
/** Server-only native marker boundary. Every method revalidates its retained original authority.
 * Claims/checkpoints serialize with native writers without changing the form's result-source revision.
 * write() must atomically finish the native marker and durable receipt after its one verified Files effect.
 */
interface FormsLinkedExportAuthority {
    public function claim(string $associationId):?array;
    public function source(array $claim):\Closure;
    public function destination(array $claim):array;
    public function checkpoint(array $claim,array $cycle):array;
    /** Retire only a proven read-only cycle, invalidate its lease; newer dirty generation returns true, otherwise durably pause. */
    public function restart(array $claim,array $cycle):bool;
    public function release(array $claim):void;
    public function pause(array $claim,string $reason):void;
    public function files(array $claim,array $submission):array;
    public function write(array $claim,string $content,array $job):array;
}
