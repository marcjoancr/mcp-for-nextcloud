<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Relocation;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
use OCA\Mcp\Modules\Contacts\Cards\ContactWriter;
use OCA\Mcp\Modules\Contacts\Deletion\ContactBackup;
use OCA\Mcp\Modules\Contacts\Execution\ContactPlan;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use Sabre\VObject\Component\VCard;
use Throwable;

final class ContactTransfer
{
    public function __construct(
        private ContactAccess $access,
        private ContactStore $store,
        private ContactRepository $repository,
        private ContactCard $cards,
        private ContactPlan $plans,
        private ContactWriter $writer,
        private ContactBackup $backup,
        private ContactDav $dav,
        private PlanState $states,
    ) {
    }

    private function prepare(array $arguments, string $userId, bool $moving): array
    {
        foreach (['sourceAddressbook', 'targetAddressbook'] as $field) {
            if ($this->access->isSystemPath($userId, $arguments[$field])) {
                throw new ToolFailure(Translator::t('Account contacts are read-only; ask the administrator.'));
            }
        }
        $source = $this->access->resolve($userId, $arguments['sourceAddressbook'], $moving);
        $target = $this->access->resolve($userId, $arguments['targetAddressbook'], true);
        if ($source->id === $target->id) {
            throw new InvalidArgumentException(Translator::t('Choose a different destination address book.'));
        }
        $row = $this->repository->row($source->id, $arguments['sourceUri']);
        if (isset($arguments['etag']) && trim($arguments['etag'], '"') !== trim($row['etag'], '"')) {
            throw new ToolFailure(FileAccessMessages::conflict());
        }
        $original = $this->cards->parse($row['data']);
        $before = $this->cards->item($original);
        $before['vcard'] = $row['data'];
        $card = $moving ? clone $original : $this->cards->duplicate($original);
        $uri = $moving
            ? 'moved-' . substr(hash('sha256', $source->path . "\0" . $row['uri'] . "\0" . $target->path), 0, 40) . '.vcf'
            : (string) $card->UID . '.vcf';
        if ($this->containsDestination($target->id, $uri, (string) $card->UID)) {
            throw new ToolFailure(FileAccessMessages::conflict());
        }
        $action = $moving ? 'contacts_move_contact' : 'contacts_copy_contact';
        $plan = $this->plans->build($action, $target, null, $before, $card, $userId);
        if (!$moving) {
            $copySummary = $this->cards->item($card);
            unset($copySummary['uid'], $copySummary['properties'], $copySummary['vcard']);
            $plan['after'] = $copySummary;
        }
        $plan['sourceAddressbook'] = (array) $source;
        $plan['sourceUri'] = $row['uri'];
        $plan['sourceEtag'] = $row['etag'];
        if ($moving) {
            $plan['destinationUri'] = $uri;
        } else {
            $plan['copyIdentity'] = 'A new UID and URI are assigned when the copy is created.';
        }
        $plan[PlanState::ARGUMENT] = $this->states->of($action, $userId, [
            'sourceBook' => ['id' => $source->id, 'uri' => $source->uri, 'ownerPrincipal' => $source->ownerPrincipal],
            'targetBook' => ['id' => $target->id, 'uri' => $target->uri, 'ownerPrincipal' => $target->ownerPrincipal],
            'source' => ['uri' => $row['uri'], 'uid' => (string) $original->UID, 'etag' => $row['etag']],
            'action' => $action,
            'destinationUri' => $moving ? $uri : 'assigned-on-execute',
        ]);
        if ($moving) {
            $sourcePlan = $this->plans->build($action, $source, $row, $before, null, $userId);
            $plan['shared'] = array_values(array_merge($plan['shared'], $sourcePlan['shared']));
            $plan['recovery'] = [
                'backupRequired' => true,
                'directory' => $this->backup->directory($source->name),
                'message' => Translator::t('A verified source vCard backup is saved before the source is deleted.'),
            ];
            $plan['recoverable'] = true;
        }
        return compact('source', 'target', 'row', 'card', 'uri', 'plan');
    }

    private function containsDestination(int $bookId, string $uri, string $uid): bool
    {
        foreach ($this->store->cards($bookId) as $row) {
            if ($row['uri'] === $uri) {
                return true;
            }
            try {
                if ((string) $this->cards->parse($row['data'])->UID === $uid) {
                    return true;
                }
            } catch (ToolFailure) {
                continue;
            }
        }
        return false;
    }

    public function previewCopy(array $arguments, string $userId): array
    {
        return $this->prepare($arguments, $userId, false)['plan'];
    }

    public function previewMove(array $arguments, string $userId): array
    {
        return $this->prepare($arguments, $userId, true)['plan'];
    }

    public function copyContact(array $arguments, string $userId): array
    {
        $prepared = $this->prepare($arguments, $userId, false);
        $this->assertConfirmed($arguments, $prepared['plan']);
        $result = $this->writer->save([
            'book' => $prepared['target'],
            'row' => null,
            'card' => $prepared['card'],
            'uri' => $prepared['uri'],
            'plan' => $prepared['plan'],
        ], $userId);
        $payload = $this->payload($result);
        if (!$this->destinationMatches($payload, $prepared)) {
            return $this->unverifiedDestination($prepared, false);
        }
        return ToolResult::json($payload + [
            'copied' => true,
            'sourceAddressbook' => $prepared['source']->path,
            'sourceUri' => $prepared['row']['uri'],
        ]);
    }

    public function moveContact(array $arguments, string $userId): array
    {
        $prepared = $this->prepare($arguments, $userId, true);
        $this->assertConfirmed($arguments, $prepared['plan'], true);
        $targetResult = $this->writer->save([
            'book' => $prepared['target'],
            'row' => null,
            'card' => $prepared['card'],
            'uri' => $prepared['uri'],
            'plan' => $prepared['plan'],
        ], $userId);
        $target = $this->payload($targetResult);
        if (!$this->destinationMatches($target, $prepared)) {
            return $this->unverifiedDestination($prepared, true);
        }
        $backupPath = null;
        try {
            $currentSource = $this->access->resolve($userId, $arguments['sourceAddressbook'], true);
            $currentTarget = $this->access->resolve($userId, $arguments['targetAddressbook'], true);
            if ($currentSource->id !== $prepared['source']->id || $currentSource->ownerPrincipal !== $prepared['source']->ownerPrincipal
                || $currentTarget->id !== $prepared['target']->id || $currentTarget->ownerPrincipal !== $prepared['target']->ownerPrincipal) {
                throw new ToolFailure(FileAccessMessages::conflict());
            }
            $latest = $this->repository->row($prepared['source']->id, $prepared['row']['uri']);
            if ($latest['etag'] !== $prepared['row']['etag'] || $latest['data'] !== $prepared['row']['data']) {
                throw new ToolFailure(FileAccessMessages::conflict());
            }
            $backupPath = $this->backup->save(
                $userId,
                $prepared['source']->name,
                $prepared['plan']['before']['name'] ?: $prepared['plan']['before']['uid'],
                $latest['data']
            );
            $destination = $this->repository->row($prepared['target']->id, $prepared['uri']);
            $destinationPayload = $this->cards->item($this->cards->parse($destination['data'])) + [
                'uri' => $destination['uri'],
                'etag' => $destination['etag'],
                'addressbook' => $prepared['target']->path,
            ];
            if ($destination['etag'] !== ($target['etag'] ?? null) || !$this->destinationMatches($destinationPayload, $prepared)) {
                return ToolResult::json($target + [
                    'moved' => false,
                    'partial' => true,
                    'sourceDeleted' => false,
                    'destinationVerified' => false,
                    'backupPath' => $backupPath,
                    'message' => Translator::t('The destination changed after the backup; the source remains in place.'),
                ]);
            }
        } catch (ToolFailure $failure) {
            return ToolResult::json($target + [
                'moved' => false,
                'partial' => true,
                'sourceDeleted' => false,
                'destinationVerified' => true,
                'backupPath' => $backupPath,
                'message' => Translator::t('The verified destination copy exists, but the source remains because it could not be safely removed: %s', [$failure->getMessage()]),
            ]);
        }
        $deleteFailure = null;
        try {
            $this->dav->delete($userId, $prepared['source']->uri, $latest['uri'], $latest['etag']);
        } catch (Throwable $error) {
            $deleteFailure = $error;
        }
        return $this->verifyMoveOutcome($prepared, $target, $backupPath, $deleteFailure);
    }

    private function verifyMoveOutcome(array $prepared, array $target, ?string $backupPath, ?Throwable $deleteFailure): array
    {
        $sourceState = 'unknown';
        try {
            $sourceState = $this->store->card($prepared['source']->id, $prepared['row']['uri']) === null
                ? 'absent'
                : 'present';
        } catch (Throwable) {
            // Keep the source state unknown. The verified backup remains the recovery copy.
        }
        $destinationVerified = false;
        try {
            $destination = $this->repository->row($prepared['target']->id, $prepared['uri']);
            $destinationPayload = $this->cards->item($this->cards->parse($destination['data'])) + [
                'uri' => $destination['uri'],
                'etag' => $destination['etag'],
                'addressbook' => $prepared['target']->path,
                'vcard' => $destination['data'],
            ];
            $destinationVerified = $destination['etag'] === ($target['etag'] ?? null)
                && $this->destinationMatches($destinationPayload, $prepared);
        } catch (Throwable) {
            // The destination read-back failed; do not report a complete move.
        }

        $moved = $sourceState === 'absent' && $destinationVerified;
        $message = null;
        if ($moved && $deleteFailure !== null) {
            $message = Translator::t('The delete request returned an error, but read-back verifies the destination and confirms the source is absent.');
        } elseif (!$moved && $sourceState === 'present') {
            $message = Translator::t('The source remains. Keep its verified backup and review the current state before retrying.');
        } elseif (!$moved) {
            $message = Translator::t('The move is incomplete or could not be fully verified. Keep the verified backup and review both contacts before retrying.');
        }
        $result = $target + [
            'moved' => $moved,
            'partial' => !$moved,
            'sourceDeleted' => $sourceState === 'absent',
            'sourceVerifiedAbsent' => $sourceState === 'absent',
            'sourceVerifiedPresent' => $sourceState === 'present',
            'sourceVerification' => $sourceState,
            'destinationVerified' => $destinationVerified,
            'backupPath' => $backupPath,
            'sourceAddressbook' => $prepared['source']->path,
            'sourceUri' => $prepared['row']['uri'],
        ];
        if ($message !== null) {
            $result['message'] = $message;
        }
        if ($deleteFailure !== null) {
            $result['deleteResponseUncertain'] = true;
        }
        return ToolResult::json($result);
    }

    private function destinationMatches(array $payload, array $prepared): bool
    {
        if (($payload['uri'] ?? null) !== $prepared['uri']
            || ($payload['addressbook'] ?? null) !== $prepared['target']->path
            || ($payload['uid'] ?? null) !== (string) $prepared['card']->UID
            || !is_string($payload['vcard'] ?? null)) {
            return false;
        }
        try {
            $actual = $this->cards->parse($payload['vcard'])->serialize();
            $expected = $this->cards->parse($prepared['card']->serialize())->serialize();
            return hash_equals($expected, $actual);
        } catch (ToolFailure) {
            return false;
        }
    }

    private function unverifiedDestination(array $prepared, bool $moving): array
    {
        return ToolResult::json([
            'copied' => false,
            'moved' => false,
            'partial' => true,
            'sourceDeleted' => false,
            'destinationVerified' => false,
            'sourceAddressbook' => $prepared['source']->path,
            'sourceUri' => $prepared['row']['uri'],
            'targetAddressbook' => $prepared['target']->path,
            'destinationUri' => $prepared['uri'],
            'message' => $moving
                ? Translator::t('The destination did not match the approved contact; the source was left in place.')
                : Translator::t('The destination could not be verified; the source remains unchanged.'),
        ]);
    }

    private function assertConfirmed(array $arguments, array $plan, bool $moving = false): void
    {
        PlanState::require($arguments);
        if (!PlanState::matches((string) $plan[PlanState::ARGUMENT], $arguments)) {
            throw new PlanChanged($plan);
        }
        if (($arguments['confirm'] ?? false) !== true) {
            throw new InvalidArgumentException(Translator::t('Confirm the contact transfer after reviewing its plan.'));
        }
        if ($moving && (!isset($arguments['etag']) || trim($arguments['etag'], '"') !== trim((string) $plan['sourceEtag'], '"'))) {
            throw new ToolFailure(FileAccessMessages::conflict());
        }
        $this->plans->assertAcknowledged($plan, $arguments);
    }

    private function payload(array $result): array
    {
        $text = $result['content'][0]['text'] ?? '';
        $payload = json_decode($text, true);
        if (!is_array($payload)) {
            throw new ToolFailure(Translator::t('The destination contact could not be verified.'));
        }
        return $payload;
    }
}
