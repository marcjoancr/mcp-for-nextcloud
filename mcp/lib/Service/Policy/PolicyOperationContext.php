<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Policy;

use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Policy\Resolution\EffectiveResult;
use OCA\Mcp\Service\Policy\Resolution\EligibilityResolution;
use OCA\Mcp\Service\Policy\Resolution\GrantResolution;

/** Explicitly owned by one operation; close in finally. Never register this snapshot as a shared service. */
final class PolicyOperationContext {
    private bool $closed = false;

    /**
     * @param array<string,array{present:bool,value?:mixed}> $raw
     * @param list<array{groupId:string,grants:array,trusted:bool,state:?string}> $candidates
     * @param array<string,bool> $apps
     */
    public function __construct(
        public readonly string $revision,
        private PolicyWriter $writer,
        private array $raw,
        private array $candidates,
        private bool $accountEnabled,
        private bool $serviceEnabled,
        private array $apps,
        private bool $logsAvailable,
        private bool $logsPermitted,
    ) {}

    public function close(): void {
        $this->closed = true;
        $this->raw = $this->candidates = $this->apps = [];
    }

    /** Candidates retain untrusted/non-active policies for diagnosis, independently of granting sources. */
    public function candidates(): array {
        $this->check();
        return $this->candidates;
    }

    public function eligible(): EligibilityResolution {
        $result = $this->resolve(GrantPolicy::ELIGIBLE_KEY, '_system', 'eligible', false);
        return new EligibilityResolution($result->allowed, $result->source, $result->groupIds, $result->rawState);
    }

    public function granted(string $module, string $operation): GrantResolution {
        self::validate($module, $operation);
        return $this->resolve('grant_' . $module . '_' . $operation, $module, $operation,
            GrantPolicy::defaultGranted($module, $operation));
    }

    public function resolveEffective(string $module, string $operation): EffectiveResult {
        $grant = $this->granted($module, $operation);
        $eligibility = $this->eligible();
        $gate = match (true) {
            !$this->accountEnabled => 'account',
            !$this->serviceEnabled => 'service',
            $eligibility->allowed === null => 'eligibility_unknown',
            !$eligibility->allowed => 'eligibility',
            ($this->raw[GrantPolicy::CONNECTED_KEY]['value'] ?? null) !== '1' => 'connection',
            !$this->apps[$module] => 'app',
            $module === 'logs' && !$this->logsAvailable => 'logs_available',
            $module === 'logs' && !$this->logsPermitted => 'logs_role',
            $grant->allowed === null => 'grant_unknown',
            !$grant->allowed => 'grant',
            default => null,
        };
        return new EffectiveResult($gate === null ? true : (str_ends_with($gate, '_unknown') ? null : false),
            $gate ?? 'allowed', $grant, $gate);
    }

    private function resolve(string $key, string $module, string $operation, bool $default): GrantResolution {
        $this->check();
        $raw = $this->raw[$key] ?? ['present' => false];
        if ($raw['present']) {
            return match ($raw['value']) {
                '1' => new GrantResolution(true, 'individual_allow', [], $raw),
                '0' => new GrantResolution(false, 'individual_deny', [], $raw),
                default => new GrantResolution(false, 'invalid', [], $raw),
            };
        }
        $sources = $unknown = [];
        foreach ($this->candidates as $candidate) {
            if (!in_array($operation, $candidate['grants'][$module] ?? [], true)) {
                continue;
            }
            if ($candidate['trusted'] && $candidate['state'] === GroupLifeMapper::ACTIVE) {
                $sources[] = $candidate['groupId'];
            } else {
                $unknown[] = $candidate['groupId'];
            }
        }
        sort($sources, SORT_STRING);
        sort($unknown, SORT_STRING);
        if ($sources !== []) {
            return new GrantResolution(true, 'group', $sources, $raw);
        }
        if ($unknown !== []) {
            return new GrantResolution(null, 'unknown', $unknown, $raw);
        }
        return new GrantResolution($default, 'default', [], $raw);
    }

    private function check(): void {
        if ($this->closed) {
            throw new PolicyUnavailable('Policy operation is closed');
        }
        $this->writer->assertUsable();
    }

    private static function validate(string $module, string $operation): void {
        if (!GrantPolicy::inCatalog($module, $operation)) {
            throw new \InvalidArgumentException('Unknown module or operation');
        }
    }
}
