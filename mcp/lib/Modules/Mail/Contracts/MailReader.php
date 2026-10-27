<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Contracts;

/** Owned account and cached-folder projection; native Mail objects stay in Integration. */
interface MailReader {
    /** @return list<array{id:int,name:string,email:string}> */
    public function accounts(string $userId): array;

    /** @return array{account:array{id:int,name:string,email:string},mailboxes:list<array<string,mixed>>}|null */
    public function mailboxes(string $userId, int $accountId): ?array;
}
