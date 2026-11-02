<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

use OCA\Mcp\Mcp\Tools\Execution\ToolExecutor;

use OCA\Mcp\Mcp\Tools\Discovery\ToolPublication;

use OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability;

use OCA\Mcp\Mcp\Guide\ToolGuide;

use OCA\Mcp\Mcp\Tools\Validation\UnknownToolException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

use InvalidArgumentException;
use OCP\IUser;
use OCA\Mcp\Access\Policy\Resolution\GrantUnknown;

/** Ordered tool discovery and first-match dispatch through shared availability and execution. */
class ToolRegistry {
    private ?ToolGuide $guide = null;

    /** @param list<ToolModule> $modules explicit module list, in tools/list order */
    public function __construct(
        private array $modules,
        private ToolAvailability $availability,
        private ToolExecutor $executor,
    ) {}

    /**
     * Every tool carries the display metadata clients show instead of the technical name:
     * `title` (Tool.title) and `annotations` derived from the tool's grant operation.
     *
     * A writing tool is published with the `confirm` argument {@see WriteGate} adds, so the model reads the
     * rule in the schema of every write it may call; a read tool is published exactly as the module wrote it.
     *
     * The guide is the first tool of the list: it is how the model finds out about the others, so a client
     * that only shows the beginning of the list still shows it.
     *
     * @param string $userId authenticated user
     * @return list<array{name:string, title:string, description:string, inputSchema:array<string, mixed>, annotations:array<string, bool|string>}> tools the user may call now
     */
    public function list(string $userId): array {
        $definitions = $this->definitions($userId);
        array_unshift($definitions, ToolPublication::present($this->guide()->definition()));
        return array_map(
            // tools/list carries only what a client needs to show and validate the tool; the grant module,
            // the operation and the app stay inside the server, for the guide.
            static fn (array $definition): array => [
                'name' => $definition['name'],
                'title' => $definition['title'],
                'description' => $definition['description'],
                'inputSchema' => $definition['inputSchema'],
                'annotations' => $definition['annotations'],
            ],
            $definitions,
        );
    }

    /**
     * The same tools tools/list answers, with the fields the guide needs to explain them: the grant module
     * and operation, the app the tool needs, the title and the annotations.
     *
     * Reading the processed definitions rather than the raw ones is what keeps the guide honest: whatever
     * the registry did to a schema before answering tools/list is what the guide describes.
     *
     * @param string $userId authenticated user
     * @return list<array<string, mixed>> one entry per tool the user may call now, guide excluded
     */
    public function definitions(string $userId): array {
        return $this->visibleDefinitions($userId, true);
    }

    /** Selectable definitions, before applying the subject's MCP grants. */
    public function administrableDefinitions(?IUser $user = null): array {
        $tools = [];
        $enabled = [];
        foreach ($this->modules as $module) {
            foreach ($module->definitions() as $definition) {
                foreach (ToolAvailability::requiredApps($definition) as $app) {
                    if (!($enabled[$app] ??= $this->availability->appEnabled($app, $user))) {
                        continue 2;
                    }
                }
                $tools[] = ToolPublication::present($definition)
                    + (isset($definition['grantAllOf']) ? ['grantAllOf' => $definition['grantAllOf']] : []);
            }
        }
        return $tools;
    }

    /** The administrative ceiling, before applying an individual connection's selection. */
    public function permittedDefinitions(string $userId): array {
        return $this->visibleDefinitions($userId, false);
    }

    private function visibleDefinitions(string $userId, bool $forConnection): array {
        $tools = [];
        foreach ($this->modules as $module) {
            if (!$this->availability->permits($module, $userId)) {
                continue;
            }
            foreach ($module->definitions() as $definition) {
                try {
                    $allowed = $forConnection ? $this->availability->allowed($definition, $userId)
                        : $this->availability->administrativelyAllowed($definition, $userId);
                } catch (GrantUnknown) {
                    // Omit only this entry; a failed policy read still fails the request.
                    continue;
                }
                if ($allowed) {
                    $tools[] = ToolPublication::present($definition);
                }
            }
        }
        return $tools;
    }

    /** Concrete admin-approved actions of a tool whose authorization depends on the arguments. */
    public function permittedVariants(string $userId, string $name): array {
        foreach ($this->modules as $module) {
            foreach ($module->definitions() as $definition) {
                if ($definition['name'] === $name && isset($definition['grantAnyOf'])) {
                    return $this->availability->permits($module, $userId) && $this->availability->administrativelyAllowed($definition, $userId)
                        ? $this->availability->permittedVariants($definition, $userId) : [];
                }
            }
        }
        return [];
    }

    /** Explicit lookup for non-tool protocol routes that expose the same capability. */
    public function allowsTool(string $userId, string $name): bool {
        foreach ($this->modules as $module) {
            foreach ($module->definitions() as $definition) {
                if ($definition['name'] === $name) {
                    return $this->availability->permits($module, $userId)
                        && $this->availability->allowed($definition, $userId);
                }
            }
        }
        return false;
    }

    /**
     * A write without `confirm: true` answers with its plan and never reaches the module; with it, the module
     * runs and every guard it holds — ACL, ETag, ownership, {@see PlanState} — is checked again at that moment. A
     * module that finds a state other than the one its plan showed throws {@see PlanChanged} and the answer is the
     * new plan, in the same envelope.
     *
     * @param string $name tool name
     * @param array<string, mixed> $arguments raw arguments from tools/call
     * @param string $userId authenticated user
     * @return array{content: list<array{type:string, text:string}>, isError?: bool} MCP tool result
     * @throws InvalidArgumentException for unknown, hidden or invalid calls (JSON-RPC -32602)
     */
    public function call(string $name, array $arguments, string $userId): array {
        if ($name === ToolGuide::TOOL) {
            return $this->describe(
                ArgumentValidator::validate($this->guide()->definition()['inputSchema'], $arguments),
                $userId,
            );
        }
        foreach ($this->modules as $module) {
            foreach ($module->definitions() as $definition) {
                if ($definition['name'] !== $name) {
                    continue;
                }
                return $this->availability->withinTool($userId, $definition,
                    fn () => $this->executor->call($module, $definition, $arguments, $userId));
            }
        }
        throw new UnknownToolException();
    }

    /**
     * The guide tool reads the definitions of this user like any other read, so it goes through the same
     * permission filter and reports a module or a tool the user cannot see as a readable refusal.
     *
     * @param array<string, mixed> $arguments validated arguments of the guide call
     * @param string $userId authenticated user
     * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>, isError?: bool}
     */
    private function describe(array $arguments, string $userId): array {
        try {
            return $this->guide()->result($this->definitions($userId), $arguments);
        } catch (ToolFailure $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * The guide is built once with the module list it reads the behaviour notes from. Public so the resource
     * registry renders mcp://guide from the very same guide the mcp_guide tool returns.
     *
     * @return ToolGuide the shared guide for this registry's modules
     */
    public function guide(): ToolGuide {
        return $this->guide ??= new ToolGuide($this->modules);
    }
}
