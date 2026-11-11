<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Operations;

use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Current original-action access is checked again; receipts never contain message bodies or attachment handles. */
final class MailOperationReader {
    public function __construct(private OperationJournal $journal, private \Closure $allowsTool,private ?\Closure $nativeEntry=null,private ?\Closure $deliveryEntry=null) {}
    public function get(string $uid, string $id): array {
        $receipt = $this->journal->get($uid, $id);
        if ($receipt === null || !$this->allowed($uid,$receipt)) throw new ToolFailure('Operation not found.');
        $result=['operation'=>$receipt];
        if($receipt['state']==='awaiting_user'&&$this->nativeEntry!==null){$entry=($this->nativeEntry)($uid,$id);if($entry!==null)$result['native_setup']=$entry;}
        if(in_array($receipt['tool'],['mail_send','mail_schedule'],true)&&$this->deliveryEntry!==null){$entry=($this->deliveryEntry)($uid,$id,$receipt['tool']);if($entry!==null)$result['delivery']=$entry;}
        return $result;
    }
    public function assertAccess(string $uid,array $receipt): void {
        if (!$this->allowed($uid,$receipt)) throw new ToolFailure('Operation not found.');
    }
    private function allowed(string $uid,array $receipt):bool {
        $tools=array_merge([$receipt['tool']],isset($receipt['source_tool'])?[$receipt['source_tool']]:[],$receipt['source_tools']??[]);
        if(count($tools)>10)return false;
        foreach($tools as $tool)if(!is_string($tool)||!($this->allowsTool)($uid,$tool))return false;
        return true;
    }
    public function list(string $uid, int $limit, int $offset): array {
        $rows = $this->journal->list($uid, $limit, $offset);
        $visible = array_values(array_filter($rows, fn(array $r): bool => $this->allowed($uid,$r)));
        return ['operations' => $visible, 'next_offset' => count($rows) === $limit ? $offset + $limit : null];
    }
}
