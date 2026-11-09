<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mutations;
use OCA\Mcp\Mcp\Operations\{NativeOperationFence,OperationStore};
/** Reads retain prepared. The existing durable fence admits only cache dispatch and its observed proof. */
final class RefreshOperationFence {
    public function __construct(private OperationStore $store,private array $lease,private array $request,private \Closure $authorize,private NativeOperationFence $writes){}
    public function authorizeRead(array $binding):bool {
        $row=$this->store->get($this->lease['scope'],$this->lease['id']);$m=$row['resource_refs']['mutation']??[];
        return $binding===$this->request&&($row['state']??null)==='running'&&($m['phase']??null)==='prepared'&&($m['descriptor']??null)===$binding&&hash_equals($m['token_hash']??'',hash('sha256',$this->lease['token']))&&($this->authorize)();
    }
    public function beforeCache(array $binding,array $observation):bool {return $this->authorizeRead($binding)&&$this->writes->beforeWire($binding)&&$this->writes->recordRemote($observation)&&$this->writes->beforeCache($binding);}
    public function recordCache(array $result):bool {
        $row=$this->store->get($this->lease['scope'],$this->lease['id']);$p=$row['resource_refs']['mutation']['remote']??[];foreach(['mailbox_id','generation','messages','reset']as$k)if(!array_key_exists($k,$p)||($result[$k]??null)!==$p[$k])return false;return $this->writes->recordCache($result);
    }
}
