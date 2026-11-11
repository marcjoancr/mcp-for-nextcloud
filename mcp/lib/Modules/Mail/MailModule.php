<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Confirmation\PreviewsWrites;
use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\ToolModule;

/** Static publication and lazy dispatch for the Mail feature. */
final class MailModule implements ToolModule, ToolGuideNotes, PreviewsWrites, RendersPlans {
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories, private \OCA\Mcp\Modules\Mail\Access\MailAccessGuard $access, private ?\OCA\Mcp\Mcp\Operations\OperationJournal $operations = null, private ?Operations\MailOperationReader $receipts = null,private ?\OCA\Mcp\Modules\Mail\Access\MailOperationAuthorization $authorization=null,private ?\OCA\Mcp\Modules\Mail\Access\MailFreshAccess $fresh=null) {
        $expected = array_keys(MailToolCatalog::definitions());
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Factories must be closures');
            }
        }
    }

    public function definitions(): array {
        return array_values(MailToolCatalog::definitions());
    }

    public function call(string $name, array $arguments, string $userId): array {
        $this->operations?->actor($userId);
        $this->access->assertReadable($userId);
        $project=$this->sieveProjectionFence($userId,$name,$arguments);
        $tool = $this->resolve($name);
        if ($this->operations === null || !WriteGate::isWrite(MailToolCatalog::definitions()[$name])) {
            $result=$tool->execute($arguments, new ToolContext($userId));$project?->__invoke();return $result;
        }
        try {
            $data = $this->operations->execute($name, $userId, $arguments, function () use ($tool, $arguments, $userId,$name): array {
                \OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->authorization?->capture($userId,$name,$arguments));
                $result = $tool->execute($arguments, new ToolContext($userId));
                if (($result['isError'] ?? false) === true) throw new \RuntimeException('Mail write did not confirm its result.');
                return $result['structuredContent'] ?? json_decode($result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
            }, function(array $receipt) use ($userId): void {
                    if ($this->receipts===null) throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Operation not found.');
                    $this->receipts->assertAccess($userId,$receipt);
            });
            if(isset($data['operation'])&&$this->receipts!==null)$this->receipts->assertAccess($userId,$data['operation']);
            $project?->__invoke();return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($data);
        } catch (\OCA\Mcp\Mcp\Confirmation\PlanChanged $e) {
            $plan=$this->operations->plan($name,$userId,$arguments,$e->plan);$project?->__invoke();throw new \OCA\Mcp\Mcp\Confirmation\PlanChanged($plan);
        }
    }

    public function preview(string $name, array $arguments, string $userId): array {
        $this->operations?->actor($userId);
        $this->access->assertReadable($userId);
        $project=$this->sieveProjectionFence($userId,$name,$arguments);
        $tool = $this->resolve($name);
        if (!$tool instanceof WriteTool || !WriteGate::isWrite(MailToolCatalog::definitions()[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $plan = $tool->preview($arguments, new ToolContext($userId));
        $plan=$this->operations?->plan($name,$userId,$arguments,$plan)??$plan;$project?->__invoke();return $plan;
    }

    public function renderPlan(string $tool, array $plan): ?string {
        return (new Presentation\MailPlanRenderer())->render($tool, $plan);
    }

    /** @return list<string> short behaviour notes about this module */
    public function guideNotes(): array {
        return Presentation\MailMessages::guideNotes();
    }

    private function sieveProjectionFence(string $uid,string $tool,array $args):?\Closure {
        if(!in_array($tool,['mail_read_filters','mail_read_absence','mail_write_filters','mail_transmit_filters','mail_write_absence','mail_enable_absence','mail_disable_absence','mail_write_signature_mode'],true))return null;
        if($this->fresh===null)throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Fresh Mail projection unavailable.');return $this->fresh->resourceFence($uid,$tool,(int)($args['account_id']??0));
    }

    private function resolve(string $name): Tool {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $tool = $this->factories[$name]();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Factory returned a different tool');
        }
        if (WriteGate::isWrite(MailToolCatalog::definitions()[$name]) && !$tool instanceof WriteTool) {
            throw new \LogicException('Mail writes must implement WriteTool');
        }
        return $tool;
    }
}
