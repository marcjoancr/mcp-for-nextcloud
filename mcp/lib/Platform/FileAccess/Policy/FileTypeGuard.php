<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\FileAccess\Policy;

use OCA\Mcp\Access\FileWritePolicy\FileWritePolicy;
use OCA\Mcp\Access\FileWritePolicy\FileTypeCheck;
use OCA\Mcp\Access\FileWritePolicy\FileTypeDenied;
use OCA\Mcp\Access\FileWritePolicy\FileWritePolicyUnavailable;

/** Evaluates only the resolved final basename; authorization, path resolution and MIME remain with callers. */
final class FileTypeGuard {
    /** @param FileWritePolicy $policy Current global policy; no user or internal-bypass argument exists. */
    public function __construct(private FileWritePolicy $policy) {
    }

    /**
     * Read once per plan/preflight or sink boundary. Reuse the result for every projected name.
     * Acquire a new snapshot at confirmation and again immediately before the actual sink.
     *
     * @return FileTypeCheck Immutable evaluation of this boundary's policy.
     * @throws FileWritePolicyUnavailable If the policy cannot be read or validated.
     */
    public function snapshot(): FileTypeCheck {
        return new FileTypeCheck($this->policy->current());
    }

    /**
     * Convenience for a boundary containing exactly one name; trees/batches must share snapshot().
     *
     * @param string $finalName Resolved final basename, never an upload's local name.
     * @return void
     * @throws FileTypeDenied If the active policy refuses the name.
     * @throws FileWritePolicyUnavailable If the policy is unavailable.
     */
    public function assertWritableName(string $finalName): void {
        $this->snapshot()->assertWritableName($finalName);
    }
    /**
     * @template T
     * @param callable():T $write Native mutation, invoked only after a fresh policy check.
     * @return T
     */
    public function writeName(string $name, callable $write): mixed {
        $this->assertWritableName($name);
        return $write();
    }

    /**
     * @template T
     * @param callable():T $write Native overwrite of the resolved current file.
     * @return T
     */
    public function writeFile(\OCP\Files\File $file, callable $write): mixed {
        return $this->writeName($file->getName(), $write);
    }
}
