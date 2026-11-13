<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Reading;
use OCP\IUserManager;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Resolved only after the gateway verifies the pinned native source and current session. */
final class FormsListingSource {
    /** @param \Closure(string):object $service */
    public function __construct(private IUserManager $users,private \Closure $service) {}
    public function rows(string $uid,array $args):array {
        $user=$this->users->get($uid);if($user===null||!$user->isEnabled())throw new ToolFailure(ToolFailure::FORBIDDEN);
        $forms=($this->service)('OCA\\Forms\\Service\\FormsService');
        $mapper=($this->service)('OCA\\Forms\\Db\\FormMapper');
        $entities=($args['kind']??'all')==='shared'?[]:$mapper->findAllByOwnerId($uid,$args['query']??null);
        if(($args['kind']??'all')!=='owned')$entities=[...$entities,...$forms->getSharedForms($user)];
        $rows=[];
        foreach($entities as $form)$rows[]=['id'=>$form->getId(),'title'=>$form->getTitle(),'ownerId'=>$form->getOwnerId(),'state'=>$form->getState(),
            'created'=>$form->getCreated(),'lastUpdated'=>$form->getLastUpdated(),'permissions'=>$forms->getPermissions($form)];
        return $rows;
    }
}
