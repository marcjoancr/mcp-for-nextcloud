<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

/** Request-local identity of the connection currently making an MCP request. */
final class ConnectionContext implements \OCA\Mcp\Modules\Files\Contracts\FileConnectionContext {
    private int $id = 0;
    private bool $selectionLoaded = false;
    /** @var array<string,true>|null null means there is no saved connection selection */
    private ?array $selection = null;
    /** @var array<string,array<string,true>> exact allowed actions for multi-action tools */
    private array $variants = [];

    /** OAuth grant id, or zero for Basic/session access. */
    public function set(int $id): void {
        $this->id = max(0, $id);
        $this->selectionLoaded = false;
        $this->selection = null;
        $this->variants = [];
    }

    public function current(): int {
        return $this->id;
    }

    public function clear(): void {
        $this->set(0);
    }

    /** @param list<string>|null $tools null means no saved selection and grants no tools
     * @param array<string,list<string>> $variants operation ceilings for multi-action tools
     */
    public function setSelection(?array $tools, array $variants = []): void {
        $this->selection = $tools === null ? null : array_fill_keys($tools, true);
        $this->variants = [];
        foreach ($variants as $tool => $operations) {
            if (is_string($tool) && is_array($operations)) {
                $this->variants[$tool] = array_fill_keys(array_filter($operations, 'is_string'), true);
            }
        }
        $this->selectionLoaded = true;
    }

    /** A missing selection is denied until the account chooses tools. */
    public function allows(string $tool): ?bool {
        if (!$this->selectionLoaded) {
            return false;
        }
        if ($this->selection === null) {
            return false;
        }
        return isset($this->selection[$tool]);
    }

    /** The exact action within a multi-action tool must have been approved for this connection. */
    public function allowsVariant(string $tool, string $operation): bool {
        return $this->allows($tool) === true && isset($this->variants[$tool][$operation]);
    }

    /** @return list<string> operations frozen for this connection */
    public function allowedVariants(string $tool): array {
        return array_keys($this->variants[$tool] ?? []);
    }
}
