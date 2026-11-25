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
    private const OWNED_DRAFT_TOOLS=['mail_read_owned_drafts','mail_write_owned_draft','mail_delete_owned_draft'];
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories, private \OCA\Mcp\Modules\Mail\Access\MailAccessGuard $access, private ?\OCA\Mcp\Mcp\Operations\OperationJournal $operations = null, private ?Operations\MailOperationReader $receipts = null,private ?\OCA\Mcp\Modules\Mail\Access\MailOperationAuthorization $authorization=null,private ?\OCA\Mcp\Modules\Mail\Access\MailFreshAccess $fresh=null,private ?\Closure $officialMode=null) {
        $known = array_keys(MailToolCatalog::definitions());
        $required = array_keys($this->official() ? MailToolCatalog::officialDefinitions() : MailToolCatalog::legacyDefinitions());
        if (array_diff($required, array_keys($factories)) !== [] || array_diff(array_keys($factories), $known) !== []) {
            throw new \LogicException('Factories must cover the published Mail catalog');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Factories must be closures');
            }
        }
    }

    public function definitions(): array {
        return array_values($this->publishedDefinitions());
    }

    public function call(string $name, array $arguments, string $userId): array {
        $official=$this->official();$published=$official?MailToolCatalog::officialDefinitions():MailToolCatalog::legacyDefinitions();
        if(!isset($published[$name])||!isset($this->factories[$name]))throw new \InvalidArgumentException('Unknown tool');
        $this->access->assertReadable($userId);
        $project=$this->projectionFence($userId,$name,$arguments,$official);
        $tool = $this->resolve($name);
        if ($this->ownedDraftTool($name)) {
            if (!$official || !$this->official()) throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('MCP-owned Mail drafts are unavailable.');
            $result=$tool->execute($arguments, new ToolContext($userId));$project?->__invoke();return $result;
        }
        if ($this->operations === null || !WriteGate::isWrite(MailToolCatalog::definitions()[$name])) {
            $this->assertModeUnchanged($official);
            $result=$tool->execute($arguments, new ToolContext($userId));$project?->__invoke();return $result;
        }
        try {
            $data = $this->operations->execute($name, $userId, $arguments, function () use ($tool, $arguments, $userId,$name,$official): array {
                $this->assertModeUnchanged($official);
                \OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->authorization?->capture($userId,$name,$arguments));
                $this->assertModeUnchanged($official);
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
        $official=$this->official();$published=$official?MailToolCatalog::officialDefinitions():MailToolCatalog::legacyDefinitions();
        if(!isset($published[$name])||!isset($this->factories[$name]))throw new \InvalidArgumentException('Unknown tool');
        $this->access->assertReadable($userId);
        $project=$this->projectionFence($userId,$name,$arguments,$official);
        if ($this->ownedDraftTool($name) && (!$official || !$this->official())) throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('MCP-owned Mail drafts are unavailable.');
        $tool = $this->resolve($name);
        if (!$tool instanceof WriteTool || !WriteGate::isWrite(MailToolCatalog::definitions()[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $this->assertModeUnchanged($official);
        $plan = $tool->preview($arguments, new ToolContext($userId));
        $this->assertModeUnchanged($official);
        if ($this->ownedDraftTool($name)) { $project?->__invoke(); return $plan; }
        $plan=$this->operations?->plan($name,$userId,$arguments,$plan)??$plan;$project?->__invoke();return $plan;
    }

    public function renderPlan(string $tool, array $plan): ?string {
        return (new Presentation\MailPlanRenderer())->render($tool, $plan);
    }

    /** @return list<string> short behaviour notes about this module */
    public function guideNotes(): array {
        return Presentation\MailMessages::guideNotes();
    }

    private function projectionFence(string $uid,string $tool,array $args,bool $official):\Closure {
        if($this->fresh===null)throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Fresh Mail projection unavailable.');
        if(in_array($tool,self::OWNED_DRAFT_TOOLS,true)) {
            if(!$official||!$this->official())throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('MCP-owned Mail drafts are unavailable.');
            $account=$args['account_id']??null;
            if(!is_int($account)||$account<1)throw new \InvalidArgumentException('A positive account_id is required.');
            return $this->fresh->resourceFence($uid,$tool,$account);
        }
        // A stored operation must keep its original references, even after its source was deleted.
        $historical=isset($args['operation_id'])&&$this->operations!==null&&$this->operations->get($uid,$args['operation_id'])!==null;
        $references=$historical||$this->authorization===null?null:fn():array=>$this->authorization->references($uid,$tool,$args);
        return $this->fresh->projectionFence($uid,$tool,$references);
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

    /** @return array<string,array<string,mixed>> */
    private function publishedDefinitions():array { return $this->official()?MailToolCatalog::officialDefinitions():MailToolCatalog::legacyDefinitions(); }
    private function official():bool { return isset($this->officialMode) && ($this->officialMode)() === true; }
    private function ownedDraftTool(string $name):bool { return in_array($name,self::OWNED_DRAFT_TOOLS,true); }
    private function assertModeUnchanged(bool $admitted):void { if($this->official()!==$admitted)throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Mail capabilities changed.'); }
}
