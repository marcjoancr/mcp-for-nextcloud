<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

/** Native Deck comment boundary; state contains no public audience presentation. */
interface CommentAccess {
	/** @return array{card:array{id:int,boardId:int,stackId:int,title:string,url:?string},board:array{owner:string,ownerDisplayName:string,name:string,shared:bool},recipientHash:string,commentIds:list<int>} */
	public function commentContext(string $userId, int $cardId): array;

	/** @return list<array<string,mixed>> Sanitized comments in native newest-first order. */
	public function listComments(string $userId, int $cardId, int $limit, int $offset): array;

	/** @return array<string,mixed> Sanitized native result. */
	public function createComment(string $userId, int $cardId, string $message): array;
}
