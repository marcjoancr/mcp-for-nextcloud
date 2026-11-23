<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Exchange;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
use OCA\Mcp\Modules\Contacts\Deletion\ContactBackup;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\Dav\Collections\Collection;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCP\Files\IRootFolder;
use Sabre\VObject\Component\VCard;

final class VcfExchange {
    public const IMPORT = 'contacts_import_vcf';
    private const MAX_BOOK_CARDS = 10000;

    public function __construct(
        private ContactAccess $access,
        private ContactStore $store,
        private ContactRepository $repository,
        private ContactCard $cards,
        private ContactDav $dav,
        private VcfBundle $bundle,
        private IRootFolder $files,
        private FilesAccessGuard $fileAccess,
        private SharedCollectionNotice $shared,
        private PlanState $states,
        private ?ContactBackup $backup = null,
    ) {}

    /** @param array{addressbook:string,uris:list<string>} $arguments */
    public function export(array $arguments, string $userId): array {
        $book = $this->access->resolve($userId, $arguments['addressbook']);
        $uris = $arguments['uris'];
        if ($uris === [] || count($uris) > VcfBundle::MAX_CARDS) {
            throw new ToolFailure(Translator::t('Select at most 100 contacts for vCard export.'));
        }
        $output = '';
        foreach (array_unique($uris) as $uri) {
            $row = $this->repository->row($book->id, $uri);
            $output .= $this->cards->parse($row['data'])->serialize();
            if (strlen($output) > VcfBundle::MAX_BYTES) {
                throw new ToolFailure(Translator::t('The vCard file exceeds the byte limit.'));
            }
        }
        return ToolResult::json([
            'filename' => 'contacts.vcf',
            'contentType' => 'text/vcard',
            'addressbook' => $book->path,
            'count' => count(array_unique($uris)),
            'vcard' => $output,
        ]);
    }

    /** @param array<string,mixed> $arguments */
    public function preview(array $arguments, string $userId): array {
        return $this->prepare($arguments, $userId)['plan'];
    }

    /** @param array<string,mixed> $arguments */
    public function import(array $arguments, string $userId): array {
        PlanState::require($arguments);
        ['book' => $book, 'plan' => $plan, 'cards' => $parsed] = $this->prepare($arguments, $userId);
        if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
            throw new PlanChanged($plan);
        }
        if ($plan['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
            throw new ToolFailure(Translator::t('Confirm changes to the shared address book separately.'));
        }

        $outcomes = [];
        foreach ($plan['contacts'] as $entry) {
            if ($entry['status'] !== 'create') {
                $outcomes[] = $entry;
                continue;
            }
            $card = $parsed[$entry['index']];
            $outcomes[] = $this->write($book, $entry, $card, $userId);
        }
        return ToolResult::json(['addressbook' => $book->path, 'contacts' => $outcomes]);
    }

    /** @param array<string,mixed> $arguments */
    public function previewReconcile(array $arguments, string $userId): array {
        return $this->prepareReconcile($arguments, $userId)['plan'];
    }

    /** @param array<string,mixed> $arguments */
    public function reconcile(array $arguments, string $userId): array {
        PlanState::require($arguments);
        $prepared = $this->prepareReconcile($arguments, $userId);
        if (!PlanState::matches($prepared['plan'][PlanState::ARGUMENT], $arguments)) {
            throw new PlanChanged($prepared['plan']);
        }
        if ($prepared['plan']['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
            throw new ToolFailure(Translator::t('Confirm changes to the shared address book separately.'));
        }

        $outcomes = [];
        foreach ($prepared['entries'] as $entry) {
            if ($entry['status'] !== 'update') {
                $outcomes[] = $this->reconcileOutcome($entry, $entry['status']);
                continue;
            }
            if ($this->backup === null) {
                $outcomes[] = $this->reconcileOutcome($entry, 'backup_unavailable');
                continue;
            }
            $backupPath = null;
            try {
                $book = $this->access->resolve($userId, $prepared['book']->path, true);
                if ($book->id !== $prepared['book']->id || $book->ownerPrincipal !== $prepared['book']->ownerPrincipal) {
                    $outcomes[] = $this->reconcileOutcome($entry, 'destination_changed');
                    continue;
                }
                $current = $this->repository->row($book->id, $entry['uri']);
                if ($current['etag'] !== $entry['etag']) {
                    $outcomes[] = $this->reconcileOutcome($entry, 'target_changed');
                    continue;
                }
                $backupPath = $this->backup->save(
                    $userId,
                    $book->name,
                    $entry['currentName'] !== '' ? $entry['currentName'] : $entry['uid'],
                    $current['data'],
                );
                $latest = $this->repository->row($book->id, $entry['uri']);
                if ($latest['etag'] !== $entry['etag']) {
                    $outcomes[] = $this->reconcileOutcome($entry, 'target_changed_after_backup', $backupPath);
                    continue;
                }
                try {
                    $this->dav->update($userId, $book->uri, $entry['uri'], $entry['etag'], $entry['card']->serialize());
                } catch (\RuntimeException) {
                    // The backend can fail after committing, so read back before returning a partial result.
                }
                $written = $this->repository->row($book->id, $entry['uri']);
                $saved = $this->cards->parse($written['data']);
                $outcome = $this->sameProperties($saved, $entry['card'], $entry['targeted'])
                    ? 'updated'
                    : 'unverified';
                $outcomes[] = $this->reconcileOutcome($entry, $outcome, $backupPath, $written['etag']);
            } catch (\Throwable) {
                $outcomes[] = $this->reconcileOutcome($entry, $backupPath === null ? 'backup_failed' : 'unverified', $backupPath);
            }
        }
        return ToolResult::json([
            'addressbook' => $prepared['book']->path,
            'source' => $prepared['plan']['source'],
            'contacts' => $outcomes,
        ]);
    }

    /** @param array<string,mixed> $arguments */
    private function prepareReconcile(array $arguments, string $userId): array {
        $book = $this->access->resolve($userId, $arguments['addressbook'], true);
        $path = PathGuard::normalize($arguments['path']);
        $sourceSnapshot = $this->readSourceSnapshot($path, $userId);
        $rows = $this->bundle->read($sourceSnapshot['data']);
        $existing = [];
        $cards = $this->store->cards($book->id);
        if (count($cards) > self::MAX_BOOK_CARDS) {
            throw new ToolFailure(Translator::t('The address book is too large to check for existing contacts.'));
        }
        foreach ($cards as $row) {
            try {
                $card = $this->cards->parse($row['data']);
                $uid = (string)$card->UID;
                if (trim($uid) !== '') { $existing[$uid][] = ['row' => $row, 'card' => $card]; }
            } catch (ToolFailure) {
                // Invalid stored cards cannot be used as an identity match.
            }
        }
        $sourceCounts = [];
        foreach ($rows as $row) {
            if ($row['card'] !== null) {
                $uid = (string)$row['card']->UID;
                $sourceCounts[$uid] = ($sourceCounts[$uid] ?? 0) + 1;
            }
        }
        $entries = [];
        $public = [];
        $state = [];
        foreach ($rows as ['index' => $index, 'card' => $source]) {
            $base = ['index' => $index, 'status' => 'invalid', 'name' => '', 'uid' => ''];
            if ($source === null) {
                $entries[] = $base;
                $public[] = $base;
                $state[] = $base;
                continue;
            }
            $uid = (string)$source->UID;
            $base['name'] = (string)($source->FN ?? '');
            $base['uid'] = $uid;
            if (($sourceCounts[$uid] ?? 0) > 1 || count($existing[$uid] ?? []) > 1) {
                $base['status'] = 'ambiguous';
                $entries[] = $base;
                $public[] = $base;
                $state[] = $base;
                continue;
            }
            $match = $existing[$uid][0] ?? null;
            if ($match === null) {
                $base['status'] = 'missing';
                $entries[] = $base;
                $public[] = $base;
                $state[] = $base;
                continue;
            }
            $target = $match['card'];
            $row = $match['row'];
            [$merged, $targeted] = $this->mergeTargetedProperties($target, $source);
            [$changes, $unchanged] = $this->propertyDiff($target, $source, $targeted);
            $base = array_replace($base, [
                'uri' => $row['uri'],
                'etag' => $row['etag'],
                'changes' => $changes,
                'unchangedFields' => $unchanged,
                'targetedFields' => array_map(static fn (string $name): string => self::FIELD_NAMES[$name], $targeted),
                'status' => $changes === [] ? 'unchanged' : 'update',
            ]);
            $entries[] = $base + [
                'card' => $merged,
                'targeted' => $targeted,
                'currentName' => (string)($target->FN ?? ''),
                'currentData' => $row['data'],
            ];
            $public[] = $base;
            $state[] = $base + ['sha256' => hash('sha256', $row['data'])];
        }
        $notice = $this->shared->forCollection($book, $userId);
        $plan = [
            'action' => 'contacts_reconcile_vcf',
            'mode' => 'reconcile',
            'addressbook' => $book->path,
            'source' => $path,
            'contacts' => $public,
            'backup' => ['required' => true, 'verifiedBeforeUpdate' => true],
            'shared' => $notice === null ? [] : [$notice],
        ];
        $plan[PlanState::ARGUMENT] = $this->states->of('contacts_reconcile_vcf', $userId, [
            'book' => ['id' => $book->id, 'uri' => $book->uri, 'owner' => $book->ownerPrincipal],
            'source' => ['path' => $path, 'etag' => $sourceSnapshot['etag'], 'sha256' => hash('sha256', $sourceSnapshot['data'])],
            'contacts' => $state,
        ]);
        return ['book' => $book, 'plan' => $plan, 'entries' => $entries];
    }

    /** @return array{string,list<string>} merged VCard and property names replaced from the source */
    private function mergeTargetedProperties(VCard $target, VCard $source): array {
        $merged = clone $target;
        $targeted = [];
        foreach ($source->children() as $property) {
            $name = strtoupper($property->name);
            if (!isset(self::FIELD_NAMES[$name])) { continue; }
            $targeted[$name] = true;
        }
        foreach (array_keys($targeted) as $name) {
            foreach ($merged->select($name) as $property) { $merged->remove($property); }
            foreach ($source->select($name) as $property) {
                $parameters = [];
                foreach ($property->parameters() as $key => $parameter) {
                    $parameters[$key] = $parameter->getValue();
                }
                $merged->add($name, $property->getParts(), $parameters, $property->group);
            }
        }
        return [$merged, array_keys($targeted)];
    }

    /** @param list<string> $targeted @return array{array<string,array{before:mixed,after:mixed}>,list<string>} */
    private function propertyDiff(VCard $target, VCard $source, array $targeted): array {
        $changes = [];
        $unchanged = [];
        foreach ($targeted as $name) {
            $beforeRaw = $this->propertyState($target, $name);
            $afterRaw = $this->propertyState($source, $name);
            $field = self::FIELD_NAMES[$name];
            if ($beforeRaw === $afterRaw) {
                $unchanged[] = $field;
                continue;
            }
            $changes[$field] = [
                'before' => $this->fieldValues($target, $name),
                'after' => $this->fieldValues($source, $name),
            ];
            $beforeParameters = $this->fieldParameters($target, $name);
            $afterParameters = $this->fieldParameters($source, $name);
            if ($beforeParameters !== $afterParameters) {
                $changes[$field]['beforeParameters'] = $beforeParameters;
                $changes[$field]['afterParameters'] = $afterParameters;
            }
        }
        return [$changes, $unchanged];
    }

    /** @return list<array{value:mixed,parameters:array<string,mixed>}> */
    private function propertyState(VCard $card, string $name): array {
        $out = [];
        foreach ($card->select($name) as $property) {
            $parameters = [];
            foreach ($property->parameters() as $key => $parameter) {
                $parameters[$key] = $parameter->getValue();
            }
            $out[] = ['value' => $property->getParts(), 'parameters' => $parameters];
        }
        return $out;
    }

    /** @return mixed values for the changed vCard field in a readable shape */
    private function fieldValues(VCard $card, string $name): mixed {
        $properties = $card->select($name);
        if (in_array($name, ['FN', 'TITLE', 'NOTE', 'URL', 'BDAY'], true)) {
            return isset($properties[0]) ? (string)$properties[0] : [];
        }
        $values = [];
        foreach ($properties as $property) {
            $parts = $property->getParts();
            if (in_array($name, ['N', 'ORG', 'ADR'], true)) {
                $values[] = $parts;
            } else {
                array_push($values, ...$parts);
            }
        }
        return $values;
    }

    /** @return list<array<string,mixed>> */
    private function fieldParameters(VCard $card, string $name): array {
        $parameters = [];
        foreach ($card->select($name) as $property) {
            $current = [];
            foreach ($property->parameters() as $key => $parameter) {
                $current[$key] = $parameter->getValue();
            }
            ksort($current);
            $parameters[] = $current;
        }
        return $parameters;
    }

    /** @param list<string> $targeted */
    private function sameProperties(VCard $actual, VCard $expected, array $targeted): bool {
        $names = [];
        foreach ([$actual, $expected] as $card) {
            foreach ($card->children() as $property) {
                $name = strtoupper($property->name);
                if ($name !== 'REV') { $names[$name] = true; }
            }
        }
        foreach (array_keys($names) as $name) {
            if ($this->canonicalPropertyState($actual, $name) !== $this->canonicalPropertyState($expected, $name)) { return false; }
        }
        return true;
    }

    /** @return list<array{value:mixed,parameters:array<string,mixed>}> */
    private function canonicalPropertyState(VCard $card, string $name): array {
        $properties = $this->propertyState($card, $name);
        foreach ($properties as &$property) { ksort($property['parameters']); }
        unset($property);
        usort($properties, static fn (array $left, array $right): int => strcmp(json_encode($left), json_encode($right)));
        return $properties;
    }

    private function reconcileOutcome(array $entry, string $status, ?string $backupPath = null, ?string $etag = null): array {
        return [
            'index' => $entry['index'],
            'uid' => $entry['uid'],
            'name' => $entry['name'],
            'status' => $status,
        ] + (isset($entry['uri']) ? ['uri' => $entry['uri']] : [])
            + ($backupPath === null ? [] : ['backupPath' => $backupPath])
            + ($etag === null ? [] : ['etag' => $etag]);
    }

    private const FIELD_NAMES = [
        'FN' => 'name', 'N' => 'structuredName', 'ORG' => 'organization', 'TITLE' => 'title',
        'NOTE' => 'note', 'URL' => 'url', 'EMAIL' => 'emails', 'TEL' => 'phones',
        'ADR' => 'addresses', 'CATEGORIES' => 'categories', 'BDAY' => 'birthday',
    ];

    /**
     * @param array<string,mixed> $arguments
     * @return array{book:Collection,plan:array<string,mixed>,cards:array<int,VCard>}
     */
    private function prepare(array $arguments, string $userId): array {
        $book = $this->access->resolve($userId, $arguments['addressbook'], true);
        $path = PathGuard::normalize($arguments['path']);
        $source = $this->readSource($path, $userId);
        $rows = $this->bundle->read($source);
        $existing = $this->existing($book);
        $preview = [];
        $cards = [];
        foreach ($rows as ['index' => $index, 'card' => $card]) {
            if ($card === null) {
                $preview[] = ['index' => $index, 'status' => 'invalid'];
                continue;
            }
            $uid = (string)$card->UID;
            $uri = 'mcp-' . hash('sha256', $uid) . '.vcf';
            $entry = ['index' => $index, 'name' => (string)$card->FN, 'uid' => $uid];
            if (isset($existing['uid:' . $uid])) {
                $preview[] = $entry + ['status' => 'already_present'];
            } elseif ($this->store->card($book->id, $uri) !== null) {
                $preview[] = $entry + ['status' => 'conflict'];
            } else {
                $cards[$index] = $card;
                $existing['uid:' . $uid] = true;
                $preview[] = $entry + ['status' => 'create', 'uri' => $uri];
            }
        }
        $notice = $this->shared->forCollection($book, $userId);
        $plan = [
            'action' => self::IMPORT,
            'addressbook' => $book->path,
            'source' => $path,
            'contacts' => $preview,
            'shared' => $notice === null ? [] : [$notice],
        ];
        $plan[PlanState::ARGUMENT] = $this->states->of(self::IMPORT, $userId, $plan + [
            'bookId' => $book->id, 'owner' => $book->ownerId, 'sourceSha256' => hash('sha256', $source),
        ]);
        return ['book' => $book, 'plan' => $plan, 'cards' => $cards];
    }

    private function readSource(string $path, string $userId): string {
        return $this->readSourceSnapshot($path, $userId)['data'];
    }

    /** @return array{data:string,etag:string} */
    private function readSourceSnapshot(string $path, string $userId): array {
        $this->fileAccess->assertScopeReadable();
        $root = $this->files->getUserFolder($userId);
        return NodeAccess::run(function () use ($root, $path): array {
            $file = NodeAccess::requireFile(NodeAccess::get($root, $path, $this->fileAccess));
            if ($file->getSize() > VcfBundle::MAX_BYTES) {
                throw new ToolFailure(Translator::t('The vCard file exceeds the byte limit.'));
            }
            $etag = $file->getEtag();
            $stream = $file->fopen('rb');
            if (!is_resource($stream)) {
                throw new ToolFailure(Translator::t('The vCard file could not be read.'));
            }
            try {
                $data = stream_get_contents($stream, VcfBundle::MAX_BYTES + 1);
            } finally {
                fclose($stream);
            }
            if (!is_string($data) || $etag !== NodeAccess::get($root, $path, $this->fileAccess)->getEtag()) {
                throw new ToolFailure(Translator::t('The vCard source changed while being read.'));
            }
            return ['data' => $data, 'etag' => $etag];
        });
    }

    /** @return array<string, true> */
    private function existing(Collection $book): array {
        $rows = $this->store->cards($book->id);
        if (count($rows) > self::MAX_BOOK_CARDS) {
            throw new ToolFailure(Translator::t('The address book is too large to check for existing contacts.'));
        }
        $uids = [];
        foreach ($rows as $row) {
            $card = $this->cards->parse($row['data']);
            $uids['uid:' . (string)$card->UID] = true;
        }
        return $uids;
    }

    /** @param array{index:int,name:string,uid:string,status:string,uri:string} $entry */
    private function write(Collection $book, array $entry, VCard $card, string $userId): array {
        try {
            $current = $this->access->resolve($userId, $book->path, true);
        } catch (ToolFailure) {
            return array_replace($entry, ['status' => 'destination_changed']);
        }
        if ($book->id !== $current->id || $book->ownerPrincipal !== $current->ownerPrincipal) {
            return array_replace($entry, ['status' => 'destination_changed']);
        }
        try {
            // CardDAV also checks UID uniqueness against the live address book.
            $this->dav->put($userId, $book->uri, $entry['uri'], $card->serialize());
        } catch (\RuntimeException) {
            // A native failure may follow a committed write. Re-read before reporting the outcome.
        }
        try {
            $written = $this->store->card($book->id, $entry['uri']);
            if ($written === null) {
                return array_replace($entry, ['status' => 'unverified']);
            }
            $saved = $this->cards->parse($written['data']);
        } catch (\RuntimeException) {
            return array_replace($entry, ['status' => 'unverified']);
        }
        if ((string)$saved->UID !== $entry['uid'] || $saved->serialize() !== $card->serialize()) {
            return array_replace($entry, ['status' => 'conflict']);
        }
        return array_replace($entry, ['status' => 'created', 'etag' => $written['etag']]);
    }
}
