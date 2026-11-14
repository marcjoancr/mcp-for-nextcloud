<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PreviewsWrites;
use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Files\Contracts\IncomingFileWriter;
use OCA\Mcp\Modules\Forms\Responses\FormsResponseExport;
use OCA\Mcp\Modules\Forms\Responses\FormsResponseSummary;

final class FormsModule implements \OCA\Mcp\Mcp\Tools\ToolModule, PreviewsWrites, RendersPlans {
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories, private ?\Closure $available = null, private ?\Closure $availabilityWindow = null) {
        $expected = array_keys(isset($factories['forms_capabilities']) ? FormsToolCatalog::definitions() : FormsToolCatalog::legacyDefinitions());
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Factories must be closures');
            }
        }
    }

    public function renderPlan(string $tool, array $plan): ?string {
        return (new Presentation\FormsPlanRenderer())->render($tool, $plan)
            ?? (isset(\OCA\Mcp\Modules\Forms\Execution\FormsCommands::commands()[$tool]) ? (new Presentation\FormsWritePlanRenderer())->render($tool, $plan) : null);
    }

    public function preview(string $name, array $arguments, string $userId): array {
        if (!isset($this->factories[$name])) { throw new \InvalidArgumentException('Unknown writing tool'); }
        $tool = ($this->factories[$name])();
        if (!$tool instanceof \OCA\Mcp\Mcp\Tools\WriteTool) { throw new \LogicException('Forms factory did not return a writing tool'); }
        return $tool->preview($arguments, new ToolContext($userId));
    }

    public function definitions(): array {
        $definitions = isset($this->factories) ? array_intersect_key(FormsToolCatalog::definitions(), $this->factories) : FormsToolCatalog::legacyDefinitions();
        $available=isset($this->availabilityWindow)?($this->availabilityWindow)():($this->available??null);
        if($available!==null&&!$available instanceof \Closure)throw new \LogicException('Forms availability window must return a closure.');
        if($available!==null)$definitions=array_filter($definitions,static fn(array $d):bool=>$available($d['name']));
        return array_values($definitions);
    }

    public function call(string $name, array $arguments, string $userId): array {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $tool = ($this->factories[$name])();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Factory returned a different tool');
        }

        return $tool->execute($arguments, new ToolContext($userId));
    }

    public static function withReader(FormsReader $reader, ?FormsResponseReader $responseReader = null, ?IncomingFileWriter $files = null, ?PlanState $states = null, ?\OCA\Mcp\Modules\Forms\Execution\FormsWriteCoordinator $commands = null, ?\Closure $available = null, ?\Closure $availabilityWindow = null): self {
        $responseReader ??= new class implements FormsResponseReader {
            public function readResponses(string $userId, int $formId, int $offset, int $limit): ?array {
                return null;
            }
        };
        $factories = [
            'forms_list' => static fn (): Tool => new Tools\ListForms($reader),
            'forms_read' => static fn (): Tool => new Tools\ReadForm($reader),
            'forms_read_responses' => static fn (): Tool => new Tools\ReadResponses($responseReader),
            'forms_summarize_responses' => static fn (): Tool => new Tools\SummarizeResponses(new FormsResponseSummary($responseReader)),
        ];
        $factories['forms_export_responses'] = static function () use ($responseReader, $files, $states): Tool {
            if ($files === null || $states === null) {
                throw new \LogicException('Forms export dependencies are not configured');
            }
            return new Tools\ExportResponses(new FormsResponseExport($responseReader, $files, $states));
        };
        if ($commands !== null) {
            foreach (\OCA\Mcp\Modules\Forms\Execution\FormsCommands::commands() as $name => $command) {
                $class = __NAMESPACE__ . '\\Tools\\' . $command['class'];
                $factories[$name] = static fn(): Tool => new $class($commands);
            }
        }
        return new self($factories, $available, $availabilityWindow);
    }
}
