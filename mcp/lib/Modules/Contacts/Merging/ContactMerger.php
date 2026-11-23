<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Merging;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
use OCA\Mcp\Modules\Contacts\Cards\ContactWriter;
use OCA\Mcp\Modules\Contacts\Deletion\ContactBackup;
use OCA\Mcp\Modules\Contacts\Execution\ContactPlan;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;
use Throwable;

final class ContactMerger
{
    public const TOOL = 'contacts_merge_contacts';
    private const MAX_CONTACTS = 10;
    private const SINGLE_FIELDS = [
        'FN' => 'name',
        'N' => 'structuredName',
        'ORG' => 'organization',
        'TITLE' => 'title',
        'NOTE' => 'note',
        'URL' => 'url',
        'BDAY' => 'birthday',
    ];

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

    /** @param array<string, mixed> $arguments */
    public function previewMerge(array $arguments, string $userId): array
    {
        return $this->prepare($arguments, $userId)['plan'];
    }

    /** @param array<string, mixed> $arguments */
    public function previewBatch(array $arguments, string $userId): array
    {
        $groups = $this->groups($arguments);
        $plans = [];
        $shown = [];
        $preparedGroups = [];
        foreach ($groups as $index => $group) {
            $prepared = $this->prepare($group, $userId);
            $prepared['index'] = $index;
            $preparedGroups[] = $prepared;
            $plans[] = ['index' => $index, ...$prepared['plan']];
            $shown[] = ['index' => $index, 'plan_state' => $prepared['plan'][PlanState::ARGUMENT]];
        }
        $this->assertDisjointGroups($preparedGroups);
        return [
            'groups' => $plans,
            PlanState::ARGUMENT => $this->states->of(self::TOOL . '.batch', $userId, $shown),
            'requiresConfirmation' => true,
        ];
    }

    /** @param array<string, mixed> $arguments */
    public function merge(array $arguments, string $userId): array
    {
        PlanState::require($arguments);
        $prepared = $this->prepare($arguments, $userId);
        $plan = $prepared['plan'];
        if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
            throw new PlanChanged($plan);
        }
        if (!$plan['ready']) {
            throw new ToolFailure(Translator::t('Choose a source contact or clear value for every conflicting field before merging.'));
        }
        if (($arguments['confirm'] ?? false) !== true) {
            throw new InvalidArgumentException(Translator::t('Confirm the contact merge after reviewing its plan.'));
        }
        $this->plans->assertAcknowledged($plan, $arguments);
        return $this->executePreparedGroup($prepared, $userId);
    }

    private function executePreparedGroup(array $prepared, string $userId): array
    {
        $plan = $prepared['plan'];
        $backupPaths = [];
        try {
            $this->assertRowsCurrent($userId, $prepared);
            foreach ($prepared['rows'] as $uri => $row) {
                $card = $prepared['cards'][$uri];
                $backupPaths[$uri] = $this->backup->save(
                    $userId,
                    $prepared['book']->name,
                    (string) $card->FN ?: (string) $card->UID,
                    $row['data'],
                );
            }
            $this->assertRowsCurrent($userId, $prepared);
        } catch (Throwable $error) {
            return $this->partial($prepared, $backupPaths, $prepared['sourceUris'], $error, false);
        }

        try {
            $written = $this->writer->save([
                'book' => $prepared['book'],
                'row' => $prepared['rows'][$prepared['keepUri']],
                'card' => $prepared['mergedCard'],
                'uri' => $prepared['keepUri'],
                'plan' => $plan,
            ], $userId);
            $primary = $this->payload($written);
        } catch (Throwable $error) {
            return $this->partial($prepared, $backupPaths, $prepared['sourceUris'], $error, false);
        }
        if (!$this->matches($primary, $prepared)) {
            return $this->partial(
                $prepared,
                $backupPaths,
                $prepared['sourceUris'],
                new ToolFailure(Translator::t('The consolidated contact did not match the approved plan.')),
                false,
                $primary,
            );
        }

        $deleted = [];
        $uncertainDeletes = [];
        foreach ($prepared['sourceUris'] as $sourceUri) {
            try {
                $this->assertPrimaryCurrent($userId, $prepared, $primary);
                $source = $this->repository->row($prepared['book']->id, $sourceUri);
                if ($source['etag'] !== $prepared['rows'][$sourceUri]['etag']
                    || $source['data'] !== $prepared['rows'][$sourceUri]['data']) {
                    throw new ToolFailure(FileAccessMessages::conflict());
                }
                $deleteFailure = null;
                try {
                    $this->dav->delete($userId, $prepared['book']->uri, $sourceUri, $source['etag']);
                } catch (Throwable $error) {
                    $deleteFailure = $error;
                }
                try {
                    $sourceAfter = $this->store->card($prepared['book']->id, $sourceUri);
                } catch (Throwable $error) {
                    $remaining = array_values(array_diff($prepared['sourceUris'], $deleted));
                    return $this->partial($prepared, $backupPaths, $remaining, $error, true, $primary, $deleted, [$sourceUri]);
                }
                if ($sourceAfter !== null) {
                    $remaining = array_values(array_diff($prepared['sourceUris'], $deleted));
                    return $this->partial(
                        $prepared,
                        $backupPaths,
                        $remaining,
                        $deleteFailure ?? new ToolFailure(Translator::t('A source contact remains after the delete request.')),
                        true,
                        $primary,
                        $deleted,
                    );
                }
                $deleted[] = $sourceUri;
                if ($deleteFailure !== null) {
                    $uncertainDeletes[] = $sourceUri;
                }
                $this->assertPrimaryCurrent($userId, $prepared, $primary);
            } catch (Throwable $error) {
                $remaining = array_values(array_diff($prepared['sourceUris'], $deleted));
                return $this->partial($prepared, $backupPaths, $remaining, $error, true, $primary, $deleted);
            }
        }

        return ToolResult::json($primary + [
            'merged' => true,
            'partial' => false,
            'sourcesDeleted' => true,
            'sourcesDeletedUris' => $deleted,
            'sourcesRemaining' => [],
            'backupPaths' => $backupPaths,
            'deleteResponseUncertainUris' => $uncertainDeletes,
        ]);
    }

    /** @param array<string, mixed> $arguments */
    public function mergeBatch(array $arguments, string $userId): array
    {
        PlanState::require($arguments);
        if (($arguments['confirm'] ?? false) !== true) {
            throw new InvalidArgumentException(Translator::t('Confirm the contact merge after reviewing its plan.'));
        }
        $groups = $this->groups($arguments);
        $preparedGroups = [];
        $shown = [];
        foreach ($groups as $index => $group) {
            $prepared = $this->prepare($group, $userId);
            $prepared['index'] = $index;
            $preparedGroups[] = $prepared;
            $shown[] = ['index' => $index, 'plan_state' => $prepared['plan'][PlanState::ARGUMENT]];
        }
        $this->assertDisjointGroups($preparedGroups);
        $state = $this->states->of(self::TOOL . '.batch', $userId, $shown);
        if (!PlanState::matches($state, $arguments)) {
            throw new PlanChanged([
                'groups' => array_map(static fn (array $entry): array => ['index' => $entry['index'], ...$entry['plan']], $preparedGroups),
                PlanState::ARGUMENT => $state,
            ]);
        }

        $results = [];
        $retry = [];
        $retryGroups = [];
        foreach ($preparedGroups as $prepared) {
            $plan = $prepared['plan'];
            if (!$plan['ready']) {
                $outcome = [
                    'status' => 'needs_choices',
                    'merged' => false,
                    'partial' => false,
                    'sourcesRemaining' => $prepared['sourceUris'],
                    'backupPaths' => [],
                    'message' => Translator::t('This group needs an explicit choice for each conflicting field.'),
                ];
            } elseif ($plan['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
                $outcome = [
                    'status' => 'needs_shared_confirmation',
                    'merged' => false,
                    'partial' => false,
                    'sourcesRemaining' => $prepared['sourceUris'],
                    'backupPaths' => [],
                    'message' => Translator::t('Confirm changes to the shared address book separately.'),
                ];
            } else {
                $outcome = $this->payload($this->executePreparedGroup($prepared, $userId));
                $outcome['status'] = ($outcome['merged'] ?? false) ? 'merged' : 'partial';
            }
            $entry = ['index' => $prepared['index'], 'keepUri' => $prepared['keepUri']] + $outcome;
            if ($entry['status'] !== 'merged') {
                $sourceUris = $entry['sourcesRemaining'] ?? $prepared['sourceUris'];
                if ($sourceUris === []) {
                    $entry['status'] = 'review_primary';
                    $entry['message'] = Translator::t('All source contacts were removed, but the consolidated contact needs review. Restore or edit it using the verified backups.');
                } else {
                    $retry[] = $entry['index'];
                    $currentPrimary = $this->store->card($prepared['book']->id, $prepared['keepUri']);
                    $retryContacts = [[
                        'uri' => $prepared['keepUri'],
                        'etag' => $currentPrimary['etag'] ?? $outcome['etag'] ?? $prepared['rows'][$prepared['keepUri']]['etag'],
                    ]];
                    foreach ($sourceUris as $uri) {
                        if (isset($prepared['rows'][$uri])) {
                            $currentSource = $this->store->card($prepared['book']->id, $uri);
                            $retryContacts[] = ['uri' => $uri, 'etag' => $currentSource['etag'] ?? $prepared['rows'][$uri]['etag']];
                        }
                    }
                    $invalidatedChoices = $this->invalidatedChoices(
                        $prepared['plan']['choices'],
                        $entry['sourcesDeletedUris'] ?? [],
                    );
                    $retryGroups[] = [
                        'index' => $prepared['index'],
                        'status' => $entry['status'],
                        'group' => [
                            'addressbook' => $prepared['book']->path,
                            'keepUri' => $prepared['keepUri'],
                            'contacts' => $retryContacts,
                            'choices' => $this->retryChoices($prepared['plan']['choices'], $entry['sourcesDeletedUris'] ?? []),
                        ],
                        'invalidatedChoices' => $invalidatedChoices,
                        'backupPaths' => $entry['backupPaths'] ?? [],
                        'requiresReview' => true,
                    ];
                }
            }
            $results[] = $entry;
        }
        return ToolResult::json([
            'groups' => $results,
            'retryGroupIndexes' => $retry,
            'retryGroups' => $retryGroups,
            'completed' => count(array_filter($results, static fn (array $result): bool => $result['status'] === 'merged')),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function groups(array $arguments): array
    {
        $groups = $arguments['groups'] ?? null;
        if (!is_array($groups) || count($groups) < 1 || count($groups) > 5) {
            throw new InvalidArgumentException(Translator::t('Choose between 1 and 5 contact groups to merge.'));
        }
        $groups = array_values($groups);
        foreach ($groups as $group) {
            if (!is_array($group) || !is_string($group['addressbook'] ?? null) || !is_array($group['contacts'] ?? null)) {
                throw new InvalidArgumentException(Translator::t('Each merge group needs one address book and a contact list.'));
            }
        }
        return $groups;
    }

    private function assertDisjointGroups(array $groups): void
    {
        $seen = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] as $uri => $_row) {
                $key = $group['book']->id . "\0" . $uri;
                if (isset($seen[$key])) {
                    throw new InvalidArgumentException(Translator::t('Merge groups must not contain the same contact.'));
                }
                $seen[$key] = true;
            }
        }
    }

    private function retryChoices(array $choices, array $deletedUris): array
    {
        foreach ($choices as $field => $uri) {
            if ($uri !== 'clear' && in_array($uri, $deletedUris, true)) {
                unset($choices[$field]);
            }
        }
        return $choices;
    }

    private function invalidatedChoices(array $choices, array $deletedUris): array
    {
        $invalidated = [];
        foreach ($choices as $field => $uri) {
            if ($uri !== 'clear' && in_array($uri, $deletedUris, true)) {
                $invalidated[] = $field;
            }
        }
        return $invalidated;
    }

    /** @param array<string, mixed> $arguments */
    private function prepare(array $arguments, string $userId): array
    {
        $path = (string) ($arguments['addressbook'] ?? '');
        if ($this->access->isSystemPath($userId, $path)) {
            throw new ToolFailure(Translator::t('Account contacts are read-only; ask the administrator.'));
        }
        $book = $this->access->resolve($userId, $path, true);
        $contacts = $arguments['contacts'] ?? [];
        if (!is_array($contacts) || count($contacts) < 2 || count($contacts) > self::MAX_CONTACTS) {
            throw new InvalidArgumentException(Translator::t('Choose between 2 and 10 contacts in one address book to merge.'));
        }
        $rows = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact) || !is_string($contact['uri'] ?? null) || !is_string($contact['etag'] ?? null)) {
                throw new InvalidArgumentException(Translator::t('Each contact needs its URI and ETag from the duplicate list.'));
            }
            $uri = $contact['uri'];
            if (isset($rows[$uri])) {
                throw new InvalidArgumentException(Translator::t('Choose each contact only once.'));
            }
            $row = $this->repository->row($book->id, $uri);
            if (!hash_equals($row['etag'], $contact['etag'])) {
                throw new ToolFailure(FileAccessMessages::conflict());
            }
            $rows[$uri] = $row;
        }
        $keepUri = (string) ($arguments['keepUri'] ?? '');
        if (!isset($rows[$keepUri])) {
            throw new InvalidArgumentException(Translator::t('Choose the contact to keep from the listed group.'));
        }
        $cards = [];
        $version = null;
        foreach ($rows as $uri => $row) {
            $card = $this->cards->parse($row['data']);
            if ($version !== null && (string) $card->VERSION !== $version) {
                throw new ToolFailure(Translator::t('Merge contacts with the same vCard version to preserve their properties safely.'));
            }
            $version = (string) $card->VERSION;
            $cards[$uri] = $card;
        }
        $choices = $arguments['choices'] ?? [];
        if (!is_array($choices)) {
            throw new InvalidArgumentException(Translator::t('Choose a source URI for each conflicting field.'));
        }
        [$merged, $conflicts, $validChoiceFields] = $this->compose($cards, $keepUri, $choices);
        foreach (array_keys($choices) as $field) {
            if (!in_array($field, $validChoiceFields, true)) {
                throw new InvalidArgumentException(Translator::t('A merge choice does not match a conflicting field.'));
            }
        }
        $before = $this->cards->item($cards[$keepUri]);
        $plan = $this->plans->build(self::TOOL, $book, $rows[$keepUri], $before, $merged, $userId);
        $plan['keepUri'] = $keepUri;
        $plan['contacts'] = [];
        foreach ($rows as $uri => $row) {
            $plan['contacts'][] = [
                'uri' => $uri,
                'etag' => $row['etag'],
                'name' => (string) $cards[$uri]->FN,
                'uid' => (string) $cards[$uri]->UID,
            ];
        }
        $plan['choices'] = $choices;
        foreach ($conflicts as &$conflict) {
            $choice = $choices[$conflict['field']] ?? null;
            $conflict['choice'] = $choice;
            $conflict['resolved'] = $choice !== null;
        }
        unset($conflict);
        $plan['conflicts'] = $conflicts;
        $plan['ready'] = count(array_filter($conflicts, static fn (array $conflict): bool => !$conflict['resolved'])) === 0;
        $plan['recoverable'] = true;
        $plan['backupRequired'] = array_keys($rows);
        $plan[PlanState::ARGUMENT] = $this->states->of(self::TOOL, $userId, [
            'addressbook' => ['id' => $book->id, 'uri' => $book->uri, 'ownerPrincipal' => $book->ownerPrincipal],
            'keepUri' => $keepUri,
            'contacts' => array_map(static fn (array $row): array => ['uri' => $row['uri'], 'etag' => $row['etag']], array_values($rows)),
            'choices' => $choices,
        ]);
        $sources = array_values(array_diff(array_keys($rows), [$keepUri]));
        return [
            'book' => $book,
            'rows' => $rows,
            'cards' => $cards,
            'keepUri' => $keepUri,
            'mergedCard' => $merged,
            'conflicts' => $conflicts,
            'plan' => $plan,
            'sourceUris' => $sources,
        ];
    }

    /**
     * @param array<string, VCard> $cards
     * @param array<string, string> $choices
     * @return array{VCard,list<array<string,mixed>>,list<string>}
     */
    private function compose(array $cards, string $keepUri, array $choices): array
    {
        $primary = $cards[$keepUri];
        $merged = clone $primary;
        $conflicts = [];
        $validChoiceFields = [];
        foreach (self::SINGLE_FIELDS as $propertyName => $field) {
            $bySource = [];
            foreach ($cards as $uri => $card) {
                $properties = array_values($card->select($propertyName));
                $bySource[$uri] = array_map(static fn (Property $property): string => $property->serialize(), $properties);
            }
            $signatures = array_unique(array_map(static fn (array $values): string => implode("\0", $values), $bySource));
            $hasConflict = count($signatures) > 1;
            $candidateRows = [];
            foreach ($cards as $uri => $card) {
                $candidateRows[] = ['uri' => $uri, 'value' => $this->fieldValue($card, $propertyName)];
            }
            if ($hasConflict) {
                $validChoiceFields[] = $field;
                $conflicts[] = ['field' => $field, 'property' => $propertyName, 'candidates' => $candidateRows];
            }
            $selectedUri = $hasConflict ? ($choices[$field] ?? null) : null;
            if ($hasConflict && $selectedUri !== null && $selectedUri !== 'clear' && !array_key_exists($selectedUri, $cards)) {
                throw new InvalidArgumentException(Translator::t('Choose a contact shown for the conflicting field, or choose clear.'));
            }
            if ($field === 'name' && $selectedUri === 'clear') {
                throw new InvalidArgumentException(Translator::t('The formatted name is required and cannot be cleared.'));
            }
            if ($hasConflict && $selectedUri === null) {
                continue;
            }
            $selectedProperties = $hasConflict
                ? ($selectedUri === 'clear' ? [] : array_values($cards[$selectedUri]->select($propertyName)))
                : array_values($primary->select($propertyName));
            if (!$hasConflict && $selectedProperties === []) {
                foreach ($cards as $card) {
                    $selectedProperties = array_values($card->select($propertyName));
                    if ($selectedProperties !== []) {
                        break;
                    }
                }
            }
            foreach ($merged->select($propertyName) as $property) {
                $merged->remove($property);
            }
            foreach ($selectedProperties as $property) {
                $merged->add(clone $property);
            }
        }

        $preferredBySource = [];
        foreach ($cards as $uri => $card) {
            $preferredBySource[$uri] = array_map(
                static fn (Property $property): string => $property->serialize(),
                $this->preferredProperties($card),
            );
        }
        $preferredSignatures = array_unique(array_map(
            static fn (array $properties): string => implode("\0", $properties),
            $preferredBySource,
        ));
        $preferredChoice = null;
        if (count($preferredSignatures) > 1) {
            $field = 'preferredEmail';
            $validChoiceFields[] = $field;
            $candidates = [];
            foreach ($cards as $uri => $card) {
                $candidates[] = ['uri' => $uri, 'value' => $this->fieldValue($card, 'EMAIL', true)];
            }
            $choice = $choices[$field] ?? null;
            $preferredChoice = $choice;
            $conflicts[] = [
                'field' => $field,
                'property' => 'EMAIL',
                'candidates' => $candidates,
                'choice' => $choice,
                'resolved' => $choice !== null,
            ];
            if ($choice !== null && $choice !== 'clear' && !array_key_exists($choice, $cards)) {
                throw new InvalidArgumentException(Translator::t('Choose a contact shown for the conflicting preferred e-mail, or choose clear.'));
            }
            if ($choice !== null) {
                $selected = $choice === 'clear' ? [] : $preferredBySource[$choice];
                foreach ($merged->select('EMAIL') as $email) {
                    $isSelected = in_array($email->serialize(), $selected, true);
                    if (!$isSelected) {
                        $this->clearPreferredEmail($email);
                    }
                }
            }
        }

        $handled = [...array_keys(self::SINGLE_FIELDS), 'UID', 'VERSION'];
        $seen = [];
        foreach ($merged->children() as $property) {
            $seen[$property->serialize()] = true;
        }
        foreach ($cards as $card) {
            foreach ($card->children() as $property) {
                if (in_array($property->name, $handled, true)) {
                    continue;
                }
                $serialized = $property->serialize();
                if (!isset($seen[$serialized])) {
                    $merged->add(clone $property);
                    $seen[$serialized] = true;
                }
            }
        }
        if (count($preferredSignatures) > 1 && $preferredChoice !== null) {
            $selected = $preferredChoice === 'clear' ? [] : $preferredBySource[$preferredChoice];
            foreach ($merged->select('EMAIL') as $email) {
                if (!in_array($email->serialize(), $selected, true)) {
                    $this->clearPreferredEmail($email);
                }
            }
        }
        return [$merged, $conflicts, $validChoiceFields];
    }

    private function fieldValue(VCard $card, string $propertyName, bool $preferred = false): string
    {
        $values = [];
        $properties = $preferred
            ? $this->preferredProperties($card)
            : array_values($card->select($propertyName));
        foreach ($properties as $property) {
            $values[] = $propertyName === 'N' ? implode(';', $property->getParts()) : (string) $property;
        }
        return implode(' / ', $values);
    }

    /** @return list<Property> */
    private function preferredProperties(VCard $card): array
    {
        return array_values(array_filter(
            $card->select('EMAIL'),
            static function (Property $email): bool {
                if (isset($email['PREF']) && (int) (string) $email['PREF'] > 0) {
                    return true;
                }
                return isset($email['TYPE']) && in_array('PREF', array_map('strtoupper', $email['TYPE']->getParts()), true);
            },
        ));
    }

    private function clearPreferredEmail(Property $email): void
    {
        unset($email['PREF']);
        if (!isset($email['TYPE'])) {
            return;
        }
        $types = array_values(array_filter(
            $email['TYPE']->getParts(),
            static fn (string $type): bool => strtoupper($type) !== 'PREF',
        ));
        if ($types === []) {
            unset($email['TYPE']);
        } else {
            $email['TYPE']->setValue($types);
        }
    }

    private function assertRowsCurrent(string $userId, array $prepared): void
    {
        $book = $this->access->resolve($userId, $prepared['book']->path, true);
        if ($book->id !== $prepared['book']->id || $book->ownerPrincipal !== $prepared['book']->ownerPrincipal) {
            throw new ToolFailure(FileAccessMessages::conflict());
        }
        foreach ($prepared['rows'] as $uri => $row) {
            $latest = $this->repository->row($book->id, $uri);
            if ($latest['etag'] !== $row['etag'] || $latest['data'] !== $row['data']) {
                throw new ToolFailure(FileAccessMessages::conflict());
            }
        }
    }

    private function matches(array $payload, array $prepared): bool
    {
        if (($payload['uri'] ?? null) !== $prepared['keepUri']
            || ($payload['addressbook'] ?? null) !== $prepared['book']->path
            || ($payload['uid'] ?? null) !== (string) $prepared['mergedCard']->UID
            || !is_string($payload['vcard'] ?? null)) {
            return false;
        }
        try {
            return hash_equals(
                $this->cards->parse($prepared['mergedCard']->serialize())->serialize(),
                $this->cards->parse($payload['vcard'])->serialize(),
            );
        } catch (ToolFailure) {
            return false;
        }
    }

    private function assertPrimaryCurrent(string $userId, array $prepared, array $primary): void
    {
        $book = $this->access->resolve($userId, $prepared['book']->path, true);
        if ($book->id !== $prepared['book']->id || $book->ownerPrincipal !== $prepared['book']->ownerPrincipal) {
            throw new ToolFailure(FileAccessMessages::conflict());
        }
        $latest = $this->repository->row($prepared['book']->id, $prepared['keepUri']);
        $payload = $this->cards->item($this->cards->parse($latest['data'])) + [
            'uri' => $latest['uri'],
            'etag' => $latest['etag'],
            'addressbook' => $prepared['book']->path,
            'vcard' => $latest['data'],
        ];
        if ($latest['etag'] !== ($primary['etag'] ?? null) || !$this->matches($payload, $prepared)) {
            throw new ToolFailure(FileAccessMessages::conflict());
        }
    }

    private function payload(array $result): array
    {
        $text = $result['content'][0]['text'] ?? '';
        $payload = json_decode($text, true);
        if (!is_array($payload)) {
            throw new ToolFailure(Translator::t('The consolidated contact could not be verified.'));
        }
        return $payload;
    }

    private function partial(
        array $prepared,
        array $backupPaths,
        array $sourcesRemaining,
        Throwable $error,
        bool $primaryVerified,
        array $primary = [],
        array $deleted = [],
        array $sourcesUnknown = [],
    ): array {
        return ToolResult::json(($primary ?: [
            'addressbook' => $prepared['book']->path,
            'uri' => $prepared['keepUri'],
            'uid' => (string) $prepared['cards'][$prepared['keepUri']]->UID,
        ]) + [
            'merged' => false,
            'partial' => true,
            'primaryVerified' => $primaryVerified,
            'sourcesDeleted' => count($deleted) === count($prepared['sourceUris']),
            'someSourcesDeleted' => $deleted !== [],
            'sourcesDeletedUris' => $deleted,
            'sourcesRemaining' => $sourcesRemaining,
            'sourcesUnknown' => $sourcesUnknown,
            'backupPaths' => $backupPaths,
            'message' => Translator::t('The merge is incomplete; keep the source contacts and verified backups, then retry after reviewing the current state. %s', [$error->getMessage()]),
        ]);
    }
}
