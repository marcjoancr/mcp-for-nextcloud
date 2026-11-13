<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
use OCP\IDBConnection;
use OCP\Server;
use OCA\Forms\Db\{FormMapper, ShareMapper, SubmissionMapper};
use OCA\Forms\Service\FormsService;
/** Durable references only; claiming precedes external effects and uncertain claims never replay. */
final class FormsEffectOutbox {
    private bool $delivering = false;
    private ?string $operation = null;
    private array $pending = [];
    public function __construct(private IDBConnection $db, private FormsRevisionFence $fence) {}
    public function operation(?string $key): void { $this->operation = $key; }
    public static function capture(string $kind, int $formId, int $entityId): bool {
        $self = Server::get(self::class);
        if ($self->delivering || !$self->fence->active()) { return false; }
        $key = hash('sha256', ($self->operation ?? bin2hex(random_bytes(32))) . ':' . $kind . ':' . $entityId);
        $self->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_effects (effect_key, operation_key, kind, form_id, entity_id, phase) VALUES (?, ?, ?, ?, ?, ?)',
            [$key, $self->operation ?? '', $kind, $formId, $entityId, 'pending']);
        $self->pending[] = $key;
        return true;
    }
    public function phases(string $operation): array {
        $rows = $this->db->executeQuery('SELECT kind, phase FROM *PREFIX*forms_mcp_effects WHERE operation_key = ?', [$operation])->fetchAll();
        return array_map(static fn(array $row): array => ['kind'=>$row['kind'],'phase'=>$row['phase']], $rows);
    }
    public function drain(): void {
        if ($this->delivering) { return; }
        $this->delivering = true;
        try {
            $keys=$this->pending;$this->pending=[];
            foreach ($keys as $key) {
                $row = $this->db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_effects WHERE effect_key = ? AND phase = ?', [$key,'pending'])->fetch();
                if (!$row) {continue;}
                $claim = $this->db->executeStatement('UPDATE *PREFIX*forms_mcp_effects SET phase = ? WHERE effect_key = ? AND phase = ?', ['uncertain', $row['effect_key'], 'pending']);
                if ($claim !== 1) { continue; }
                try {
                    $form = Server::get(FormMapper::class)->findById((int)$row['form_id']);
                    $service = Server::get(FormsService::class);
                    if ($row['kind'] === 'submission') {
                        $service->notifyNewSubmission($form, Server::get(SubmissionMapper::class)->findById((int)$row['entity_id']));
                    } elseif ($row['kind'] === 'share') {
                        $service->notifyNewShares($form, Server::get(ShareMapper::class)->findById((int)$row['entity_id']));
                    } else { throw new \RuntimeException('Unknown effect kind'); }
                    // Native Activity catches delivery exceptions. A return proves invocation, never delivery.
                    $this->db->executeStatement('UPDATE *PREFIX*forms_mcp_effects SET phase = ? WHERE effect_key = ?', ['attempted_unverified', $row['effect_key']]);
                } catch (\Throwable) { /* Durable uncertain claim prevents duplicate Activity/email/event dispatch. */ }
            }
        } finally { $this->delivering = false; }
    }
}
