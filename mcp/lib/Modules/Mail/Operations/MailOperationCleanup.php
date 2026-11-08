<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Operations;

use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/** Never touches active/uncertain repairs or native attachments associated with any local message. */
class MailOperationCleanup {
    public function __construct(private OperationsMapper $store, private MailServices $mail, private ITimeFactory $clock, private ?\OCA\Mcp\Db\MailSetupWorkflowsMapper $workflows = null, private ?\OCA\Mcp\Db\MailProbeSlotsMapper $slots = null) {}
    public function run(): void {
        foreach ($this->store->cleanupCandidates($this->clock->getTime()-86400,100) as $row) {
            if ($row['state']==='partial' && !$this->store->claimPartial($row['scope_key'],$row['operation_id'],'cleaning')) continue;
            try {
                $staged=$row['resource_refs']['staged']??[];
                if ($staged!==[]) {
                    $native=$this->mail->attachmentService($row['actor_uid']);
                    foreach ($staged as $ref) {
                        try { [$attachment]=$native->getAttachment($row['actor_uid'],(int)$ref['attachment_id']); }
                        catch (DoesNotExistException|\OCA\Mail\Exception\AttachmentNotFoundException) {
                            // Native deletion removes metadata before bytes; retry the storage step even if metadata is gone.
                            $native->deleteAttachment($row['actor_uid'],(int)$ref['attachment_id']);
                            continue;
                        }
                        if ($attachment->getLocalMessageId()!==null) continue;
                        $native->deleteAttachment($row['actor_uid'],(int)$ref['attachment_id']);
                    }
                }
                $this->store->cleaned($row['scope_key'],$row['operation_id']);
            } catch (\Throwable) {
                // Retain the claim and all identities for idempotent cleanup; never repeat a native write.
            }
        }
        $this->store->compact($this->clock->getTime()-30*86400);
        $this->workflows?->compact($this->clock->getTime()-30*86400);
        $this->slots?->compact($this->clock->getTime());
    }
}
