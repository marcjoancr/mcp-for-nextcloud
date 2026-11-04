<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

/** Reads submissions only after Forms' native results permission check. */
interface FormsResponseReader {
    /** Null means the current Forms user cannot access the form's results. @return array{formId: int, isAnonymous: bool, submissions: list<array<string, mixed>>, fileQuestionIds?: list<int>, questions?: list<array{id:int,text?:string,type?:string}>, questionsTruncated?: bool}|null */
    public function readResponses(string $userId, int $formId, int $offset, int $limit): ?array;
}
