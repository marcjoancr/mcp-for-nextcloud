<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Integration\Deck\DeckFixture as F;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCA\Deck\Service\LabelService;
use OCA\Deck\Service\AttachmentService;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Files\IRootFolder;
$cards = Server::get(CardService::class);
$stacks = Server::get(StackService::class);
$labels = Server::get(LabelService::class);
$attachments = Server::get(AttachmentService::class);
$root = Server::get(IRootFolder::class);
$shares = Server::get(IManager::class);
function attempt(Closure $fn): array {
    try { $fn(); return ['accepted' => true, 'exception' => null]; }
    catch (OCA\Deck\NoPermissionException|OCA\Deck\StatusException|OCP\Share\Exceptions\GenericShareException $e) {
        return ['accepted' => false, 'exception' => $e::class];
    }
}
function labelIds(object $card): array {
    $ids = array_map(fn ($l) => (int)$l->getId(), $card->getLabels()); sort($ids); return $ids;
}
function dependentIds(object $card): array {
    $ids = $card->getDependentCards() ?? []; sort($ids); return $ids;
}
switch ($action) {
    case 'seed':
        $suffix = bin2hex(random_bytes(6));
        $board = $boards->create('Integration Deck ' . $suffix, $uid, '008800');
        $stack = $stacks->create('To do', $board->getId(), 0);
        $card = $cards->create('Design ' . $suffix, $stack->getId(), 'plain', 0, $uid);
        $out = ['board' => $board->getId(), 'stack' => $stack->getId(), 'a' => $card->getId()];
        $out['b'] = $cards->create('Development', $stack->getId(), 'plain', 1, $uid)->getId();
        $private = $boards->create('Private ' . $suffix, $uid, '880000');
        $privateStack = $stacks->create('Private', $private->getId(), 0);
        $out['privateBoard'] = $private->getId();
        $out['privateCard'] = $cards->create('Private reference', $privateStack->getId(), 'plain', 0, $uid)->getId();
        $out['foreignLabel'] = $labels->create('Other board', '880000', $private->getId())->getId();
        $out['keep'] = $labels->create('Keep', '008800', $board->getId())->getId();
        $out['toggle'] = $labels->create('Toggle', '880000', $board->getId())->getId();
        $boards->addAcl($board->getId(), 0, F::READER, false, false, false);
        $boards->addAcl($board->getId(), 0, F::EDITOR, true, false, false);
        $out['fileName'] = 'deck-fixture-' . $suffix . '.txt';
        $file = $root->getUserFolder($uid)->newFile($out['fileName'], 'Native Deck fixture bytes ' . $suffix);
        $out['fileId'] = $file->getId();
        $out['hash'] = hash('sha256', $file->getContent());
        break;
    case 'trash-reference':
        $cards->delete($in['privateCard']);
        $out = ['trashed' => true];
        break;
    case 'dependency-seed':
        if (method_exists($cards, 'assignDependentCard')) {
            $cards->assignDependentCard($in['a'], $in['b']);
            $cards->assignDependentCard($in['a'], $in['privateCard']);
        }
        $out = ['nativeAvailable' => method_exists($cards, 'assignDependentCard')];
        break;
    case 'dependency-private-access':
        foreach ([F::READER, F::EDITOR] as $participant) {
            if ($in['allow']) {
                $boards->addAcl($in['privateBoard'], 0, $participant, false, false, false);
            } else {
                foreach ($boards->find($in['privateBoard'])->getAcl() as $acl) {
                    if ($acl->getParticipant() === $participant) { $boards->deleteAcl($acl->getId()); }
                }
            }
        }
        $out = ['updated' => true];
        break;
    case 'dependency-hide-remove':
        $cards->removeDependentCard($in['a'], $in['privateCard']);
        $out = ['removed' => true];
        break;
    case 'dependency-cycle':
        $cards->assignDependentCard($in['b'], $in['a']);
        $out = ['cycle' => true];
        break;
    case 'dependency-native-link':
        $out = attempt(fn () => ($in['remove'] ?? false)
            ? $cards->removeDependentCard($in['origin'], $in['target'])
            : $cards->assignDependentCard($in['origin'], $in['target']));
        break;
    case 'dependency-ui-state':
        $out = ['ids' => dependentIds($cards->find($in['a']))];
        break;
    case 'dependency-extra':
        $out = ['c' => $cards->create('Third readable', $in['stack'], 'plain', 2, $uid)->getId()];
        break;
    case 'files-prepare':
        $suffix = bin2hex(random_bytes(5));
        $ownerFolder = $root->getUserFolder($uid)->newFolder('dk4a-visible-' . $suffix);
        $visible = $ownerFolder->newFile('visible.txt', 'DK4a original bytes ' . $suffix);
        $secret = $root->getUserFolder($uid)->newFile('dk4a-secret-' . $suffix . '.txt', 'DK4a excluded bytes');
        $tag = Server::get(OCP\SystemTag\ISystemTagManager::class)->createTag('dk4a-excluded-' . $suffix, true, true);
        Server::get(OCP\IConfig::class)->setAppValue('mcp', 'hidden_system_tags', json_encode([(string)$tag->getId()], JSON_THROW_ON_ERROR));
        Server::get(OCP\SystemTag\ISystemTagObjectMapper::class)->assignTags((string)$secret->getId(), 'files', [(string)$tag->getId()]);
        $out = ['visibleFileId' => $visible->getId(), 'visibleHash' => hash('sha256', $visible->getContent()),
            'secretFileId' => $secret->getId(), 'secretName' => $secret->getName(), 'excludeTag' => $tag->getId(),
            'visibleFolderId' => $ownerFolder->getId()];
        foreach (['visibleShare' => [$visible, $in['a']], 'secondShare' => [$visible, $in['b']],
            'hiddenShare' => [$secret, $in['a']], 'folderShare' => [$ownerFolder, $in['a']]] as $key => [$node, $cardId]) {
            $share = $shares->newShare()->setNode($node)->setShareType(IShare::TYPE_DECK)
                ->setSharedWith((string)$cardId)->setPermissions(1)->setSharedBy($uid);
            $out[$key] = (int)$shares->createShare($share)->getId();
        }
        $share = $shares->newShare()->setNode($ownerFolder)->setShareType(IShare::TYPE_USER)
            ->setSharedWith(F::EDITOR)->setPermissions(1)->setSharedBy($uid);
        $out['independentShare'] = $shares->createShare($share)->getFullId();
        $direct = $shares->createShare($shares->newShare()->setNode($visible)->setShareType(IShare::TYPE_USER)
            ->setSharedWith(F::EDITOR)->setPermissions(1)->setSharedBy($uid));
        $out['independentFileShare'] = ['fullId' => $direct->getFullId(), 'id' => $direct->getId(),
            'fileId' => (int)$direct->getNodeId(), 'type' => $direct->getShareType(),
            'permissions' => $direct->getPermissions(), 'recipient' => $direct->getSharedWith()];
        break;
    case 'files-inspect':
        $file = $root->getUserFolder($uid)->getById($in['visibleFileId'])[0];
        $out = ['fileId' => $file->getId(), 'hash' => hash('sha256', $file->getContent())];
        // Same native source used by the Deck UI, in a fresh process; no visual-browser claim.
        foreach (['a', 'b'] as $key) {
            $out[$key] = array_values(array_map(static fn ($attachment): int => (int)$attachment->getId(),
                array_filter($attachments->findAll($in[$key]), static fn ($attachment): bool => $attachment->getType() === 'file')));
            sort($out[$key]);
        }
        try { $out['independentExists'] = $shares->getShareById($in['independentShare'])->getShareType() === IShare::TYPE_USER; }
        catch (OCP\Share\Exceptions\ShareNotFound) { $out['independentExists'] = false; }
        try {
            $direct = $shares->getShareById($in['independentFileShare']['fullId']);
            $out['independentFileShare'] = ['fullId' => $direct->getFullId(), 'id' => $direct->getId(),
                'fileId' => (int)$direct->getNodeId(), 'type' => $direct->getShareType(),
                'permissions' => $direct->getPermissions(), 'recipient' => $direct->getSharedWith()];
        } catch (OCP\Share\Exceptions\ShareNotFound) {
            $out['independentFileShare'] = null;
        }
        break;
    case 'files-tag-visible':
        Server::get(OCP\SystemTag\ISystemTagObjectMapper::class)->assignTags((string)$in['visibleFileId'], 'files', [(string)$in['excludeTag']]);
        $out = ['tagged' => true];
        break;
    case 'files-scope':
        $writer = Server::get(OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class);
        $state = Server::get(OCA\Mcp\Db\PolicyStateMapper::class)->state();
        $writer->setResourceScope($state['revision'], 'admin', $in['actor'], 'files', $in['items']);
        $out = ['changed' => true];
        break;
    case 'mcp-card-files':
        $module = (new \OCA\Mcp\AppInfo\Application())->getContainer()->get(\OCA\Mcp\Modules\Deck\DeckModule::class);
        $policy = Server::get(OCA\Mcp\Access\Grants\GrantPolicy::class);
        $policy->setGrant($uid, 'deck', 'read', true);
        $policy->setGrant($uid, 'deck', 'edit', $in['deckEdit'] ?? true);
        $policy->setGrant($uid, 'files', 'read', $in['filesRead'] ?? true);
        $policy->setGrant($uid, 'files', 'share', false); // Unlink reduces exposure and must not require this grant.
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $apps, Server::get(OCP\IUserManager::class), Server::get(\OCA\Mcp\Access\Guests\GuestRestrictions::class));
        $registry = new \OCA\Mcp\Mcp\Tools\ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, Server::get(Psr\Log\LoggerInterface::class)));
        $tool = ($in['unlink'] ?? false) ? 'deck_unlink_card_file' : 'deck_list_card_files';
        try { $out = ['result' => $policy->within($uid, fn () => $registry->call($tool, $in['arguments'], $uid))]; }
        catch (InvalidArgumentException) { $out = ['refused' => true]; }
        break;
    case 'mcp-dependency':
        $module = (new \OCA\Mcp\AppInfo\Application())->getContainer()->get(\OCA\Mcp\Modules\Deck\DeckModule::class);
        $policy = Server::get(OCA\Mcp\Access\Grants\GrantPolicy::class);
        $policy->setGrant($uid, 'deck', 'edit', $in['grant'] ?? true);
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $apps, Server::get(OCP\IUserManager::class), Server::get(\OCA\Mcp\Access\Guests\GuestRestrictions::class));
        $registry = new \OCA\Mcp\Mcp\Tools\ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, Server::get(Psr\Log\LoggerInterface::class)));
        $out = ['tools' => array_column($registry->list($uid), 'name')];
        $tool = ($in['remove'] ?? false) ? 'deck_remove_card_dependency' : 'deck_add_card_dependency';
        try { $out['result'] = $registry->call($tool, $in['arguments'], $uid); }
        catch (InvalidArgumentException) { $out['refused'] = true; }
        if ((int)getenv('MCP_IT_NC') < 34) {
            try { $module->call($tool, $in['arguments'], $uid); }
            catch (InvalidArgumentException) { $out['directRefused'] = true; }
        }
        break;
    case 'dependency-read':
        $module = (new \OCA\Mcp\AppInfo\Application())->getContainer()->get(\OCA\Mcp\Modules\Deck\DeckModule::class);
        $policy = Server::get(OCA\Mcp\Access\Grants\GrantPolicy::class);
        $policy->setGrant($uid, 'deck', 'read', $in['grant'] ?? true);
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $apps, Server::get(OCP\IUserManager::class), Server::get(\OCA\Mcp\Access\Guests\GuestRestrictions::class));
        $registry = new \OCA\Mcp\Mcp\Tools\ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, Server::get(Psr\Log\LoggerInterface::class)));
        $out = ['tools' => array_column($registry->list($uid), 'name')];
        try {
            $out['result'] = $registry->call('deck_read_card_dependencies', ['cardId' => $in['cardId']], $uid);
        } catch (InvalidArgumentException $e) { $out['refused'] = true; }
        if ((int)getenv('MCP_IT_NC') < 34) {
            try { $module->call('deck_read_card_dependencies', ['cardId' => $in['cardId']], $uid); }
            catch (InvalidArgumentException $e) { $out['directRefused'] = true; }
        }
        break;
    case 'mcp-list-labels':
        $module = (new \OCA\Mcp\AppInfo\Application())->getContainer()->get(\OCA\Mcp\Modules\Deck\DeckModule::class);
        $out = ['result'=>$module->call('deck_list_labels', $in['arguments'], $uid)];
        break;
    case 'mcp-labels':
        $module = (new \OCA\Mcp\AppInfo\Application())->getContainer()->get(\OCA\Mcp\Modules\Deck\DeckModule::class);
        try {
            if (OCA\Mcp\Mcp\Confirmation\WriteGate::confirmed($in['arguments'])) {
                $result = $module->call('deck_edit_card_labels', $in['arguments'], $uid);
            } else {
                $plan = $module->preview('deck_edit_card_labels', $in['arguments'], $uid);
                $result = OCA\Mcp\Modules\Deck\Execution\DeckResult::ok(OCA\Mcp\Mcp\Confirmation\WriteGate::envelope(
                    ['name' => 'deck_edit_card_labels'], $plan));
            }
            $out = ['result'=>$result];
        } catch (OCA\Mcp\Mcp\Confirmation\PlanChanged $e) { $out = ['changedPlan'=>$e->plan]; }
        catch (OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) { $out = ['result'=>OCA\Mcp\Modules\Deck\Execution\DeckResult::error($e->getMessage())]; }
        // Read through the same service that populates the native Deck UI, in this fresh actor request.
        try { $out['nativeLabels'] = labelIds($cards->find($in['arguments']['cardId'])); }
        catch (OCA\Deck\NoPermissionException) { $out['nativeLabels'] = null; }
        break;
    case 'label-third':
        $out['third'] = $labels->create('Preserve third', '111111', $in['board'])->getId();
        $cards->assignLabel($in['a'], $out['third']);
        break;
    case 'label-concurrent':
        $cards->assignLabel($in['a'], $in['keep']); $out=['changed'=>true]; break;
    case 'label-archive':
        if (($in['target'] ?? 'card') === 'card') { $cards->archive($in['a']); }
        else { $board=$boards->find($in['board']); $boards->update($in['board'],$board->getTitle(),$board->getColor(),true); }
        $out=['archived'=>true]; break;
    case 'label-revoke':
        foreach ($boards->find($in['board'])->getAcl() as $acl) {
            if ($acl->getParticipant() === F::EDITOR) { $boards->deleteAcl($acl->getId()); }
        }
        $out=['revoked'=>true]; break;
    case 'label-move-probe':
        $cards->assignLabel($in['a'], $in['keep']);
        $private = $cards->find($in['privateCard']);
        $cards->reorder($in['a'], $private->getStackId(), 1);
        $out = ['labelsAfterMove' => labelIds($cards->find($in['a']))];
        break;
    case 'label-move':
        $private = $cards->find($in['privateCard']);
        $cards->reorder($in['a'], $private->getStackId(), 1);$out=['moved'=>true]; break;
    default: throw new RuntimeException('Unknown selected Deck capability fixture action');
}
echo json_encode($out, JSON_THROW_ON_ERROR);
