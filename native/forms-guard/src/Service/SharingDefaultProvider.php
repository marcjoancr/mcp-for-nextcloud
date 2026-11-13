<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

/** Evidence from the exact native INSERT path, distinct from Manager reusing a concurrent existing share. */
final class SharingDefaultProvider extends \OC\Share20\DefaultShareProvider
{
    private array $created = [];
    private ?\Closure $beforeWrite = null;
    public function setWriteBoundary(\Closure $beforeWrite): void
    {
        $this->beforeWrite = $beforeWrite;
    }
    private function authorizeWrite(\OCP\Share\IShare $share, bool $create): void
    {
        if ($this->beforeWrite === null) {
            throw new \RuntimeException('Native managed share write boundary is unavailable');
        }
        ($this->beforeWrite)($share, $create);
    }
    public function delete(\OCP\Share\IShare $share)
    {
        $this->authorizeWrite($share, false);
        return parent::delete($share);
    }
    public function create(\OCP\Share\IShare $share)
    {
        $this->authorizeWrite($share, true);
        $result = parent::create($share);
        $this->created[$result->getFullId()] = true;
        return $result;
    }
    public function createdHere(string $id): bool
    {
        return isset($this->created[$id]);
    }
}
