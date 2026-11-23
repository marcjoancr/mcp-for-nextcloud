<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Cards;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use Throwable;
/** Patches the parsed card, preserving untouched properties, parameters, groups and version. */
class ContactCard
{
    private const FIELDS = [
        'name' => 'FN',
        'organization' => 'ORG',
        'title' => 'TITLE',
        'note' => 'NOTE',
        'url' => 'URL',
    ];
    /**
     * Reads a vCard with the identity properties required for safe contact operations.
     *
     * @param string $data complete serialized DAV object
     * @return VCard
     * @throws ToolFailure when the vCard is malformed or lacks required identity properties
     */
    public function parse(string $data): VCard
    {
        try {
            $card = Reader::read($data);
        } catch (Throwable $error) {
            throw new ToolFailure(Translator::t('The contact could not be read safely.'), 0, $error);
        }
        if (!$card instanceof VCard || !isset($card->UID) || !isset($card->FN)) {
            throw new ToolFailure(Translator::t('The contact could not be read safely.'));
        }
        return $card;
    }
    /**
     * Creates a vCard with a new UID and applies the supplied contact fields.
     *
     * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
     * @return VCard
     */
    public function create(array $arguments): VCard
    {
        $card = new VCard(
            [
                'VERSION' => '3.0',
                'UID' => bin2hex(random_bytes(16)),
                'FN' => $arguments['name'],
            ]
        );
        return $this->patch($card, $arguments);
    }
    /**
     * Clones a contact for a distinct destination entry while preserving its vCard version and properties.
     */
    public function duplicate(VCard $original): VCard
    {
        $card = clone $original;
        $card->UID->setValue(bin2hex(random_bytes(16)));
        return $card;
    }
    /**
     * Clones a contact and edits supplied fields without discarding unrelated properties.
     *
     * @param VCard $original original object, which remains unchanged
     * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
     * @return VCard
     */
    public function patch(VCard $original, array $arguments): VCard
    {
        $card = clone $original;
        foreach (self::FIELDS as $argument => $property) {
            if (!array_key_exists($argument, $arguments)) {
                continue;
            }
            if ($property === 'ORG' && isset($card->ORG)) {
                $parts = $card->ORG->getParts();
                $parts[0] = $arguments[$argument];
                $card->ORG->setParts($parts);
            } elseif (isset($card->{$property})) {
                $card->{$property}->setValue($arguments[$argument]);
            } else {
                $card->add($property, $arguments[$argument]);
            }
        }
        $this->patchStructuredName($card, $arguments);
        foreach (['emails' => 'EMAIL', 'phones' => 'TEL'] as $argument => $property) {
            if (array_key_exists($argument, $arguments)) {
                $this->patchSimpleProperties($card, $property, $arguments[$argument]);
            }
        }
        if (array_key_exists('addresses', $arguments)) {
            $this->patchAddresses($card, $arguments['addresses']);
        }
        if (array_key_exists('categories', $arguments)) {
            $this->patchCategories($card, $arguments['categories']);
        }
        if (array_key_exists('birthday', $arguments)) {
            if ($arguments['birthday'] === '') {
                foreach ($card->select('BDAY') as $property) {
                    $card->remove($property);
                }
            } elseif (isset($card->BDAY)) {
                $card->BDAY->setValue($arguments['birthday']);
            } else {
                $card->add('BDAY', $arguments['birthday']);
            }
        }
        if (array_key_exists('preferredEmail', $arguments)) {
            $this->patchPreferredEmail($card, $arguments['preferredEmail']);
        }
        return $card;
    }
    private function patchStructuredName(VCard $card, array $arguments): void
    {
        $components = ['familyName', 'givenName', 'additionalName', 'namePrefix', 'nameSuffix'];
        if (array_intersect(array_keys($arguments), $components) === []) {
            return;
        }
        $parts = isset($card->N) ? array_pad($card->N->getParts(), 5, '') : array_fill(0, 5, '');
        foreach ($components as $index => $argument) {
            if (array_key_exists($argument, $arguments)) {
                $parts[$index] = $arguments[$argument];
            }
        }
        if (isset($card->N)) {
            $card->N->setParts($parts);
        } else {
            $card->add('N', $parts);
        }
    }
    private function patchSimpleProperties(VCard $card, string $property, array $values): void
    {
        $existing = array_values($card->select($property));
        foreach ($values as $index => $value) {
            if (isset($existing[$index])) {
                $existing[$index]->setValue($value);
            } else {
                $card->add($property, $value);
            }
        }
        foreach (array_slice($existing, count($values)) as $removed) {
            $card->remove($removed);
        }
    }
    private function patchAddresses(VCard $card, array $addresses): void
    {
        $existing = array_values($card->select('ADR'));
        foreach ($addresses as $index => $address) {
            $parts = array_map(static fn(string $field): string => (string) ($address[$field] ?? ''), [
                'postOfficeBox', 'extended', 'street', 'locality', 'region', 'postalCode', 'country',
            ]);
            if (isset($existing[$index])) {
                $existing[$index]->setParts($parts);
                if (array_key_exists('type', $address)) {
                    $existing[$index]['TYPE'] = $address['type'];
                }
            } else {
                $property = $card->add('ADR', $parts);
                if (isset($address['type']) && $address['type'] !== '') {
                    $property['TYPE'] = $address['type'];
                }
            }
        }
        foreach (array_slice($existing, count($addresses)) as $removed) {
            $card->remove($removed);
        }
    }
    private function patchPreferredEmail(VCard $card, string $preferred): void
    {
        $emails = array_values($card->select('EMAIL'));
        $selected = null;
        if ($preferred !== '') {
            foreach ($emails as $email) {
                if (mb_strtolower(trim((string) $email)) === mb_strtolower(trim($preferred))) {
                    $selected = $email;
                    break;
                }
            }
            if ($selected === null) {
                throw new \InvalidArgumentException('Preferred email must match one of the contact emails.');
            }
        }
        foreach ($emails as $email) {
            unset($email['PREF']);
            if (isset($email['TYPE'])) {
                $types = array_values(array_filter(
                    $email['TYPE']->getParts(),
                    static fn(string $type): bool => strtoupper($type) !== 'PREF'
                ));
                if ($types === []) {
                    unset($email['TYPE']);
                } else {
                    $email['TYPE']->setValue($types);
                }
            }
        }
        if ($selected === null) {
            return;
        }
        if ((string) $card->VERSION === '4.0') {
            $selected['PREF'] = '1';
            return;
        }
        $types = isset($selected['TYPE']) ? $selected['TYPE']->getParts() : [];
        $selected['TYPE'] = array_values(array_unique([...$types, 'PREF']));
    }
    private function patchCategories(VCard $card, array $categories): void
    {
        $existing = array_values($card->select('CATEGORIES'));
        if ($categories === []) {
            foreach ($existing as $property) {
                $card->remove($property);
            }
            return;
        }
        if ($existing === []) {
            $card->add('CATEGORIES', $categories);
            return;
        }
        $existing[0]->setParts($categories);
        foreach (array_slice($existing, 1) as $property) {
            $card->remove($property);
        }
    }
    /**
     * Returns understood fields, every original property and the complete serialized vCard.
     *
     * @param VCard $card parsed contact card
     * @return array<string, mixed>
     */
    public function item(VCard $card): array
    {
        $item = ['uid' => (string) $card->UID];
        foreach (self::FIELDS as $argument => $property) {
            $item[$argument] = isset($card->{$property}) ? (string) $card->{$property} : '';
        }
        if (isset($card->ORG)) {
            $item['organization'] = $card->ORG->getParts()[0] ?? '';
        }
        foreach (['emails' => 'EMAIL', 'phones' => 'TEL'] as $argument => $property) {
            $item[$argument] = array_values(array_map(static fn($property) => (string) $property, $card->select($property)));
        }
        if (isset($card->N)) {
            $nameParts = array_pad($card->N->getParts(), 5, '');
            foreach (['familyName', 'givenName', 'additionalName', 'namePrefix', 'nameSuffix'] as $index => $field) {
                $item[$field] = (string) $nameParts[$index];
            }
        }
        if (isset($card->BDAY)) {
            $item['birthday'] = (string) $card->BDAY;
        }
        foreach ($card->select('EMAIL') as $email) {
            $types = isset($email['TYPE']) ? array_map('strtoupper', $email['TYPE']->getParts()) : [];
            if (in_array('PREF', $types, true) || (isset($email['PREF']) && (int) (string) $email['PREF'] > 0)) {
                $item['preferredEmail'] = (string) $email;
                break;
            }
        }
        if (count($card->select('ADR')) > 0) {
            $item['addresses'] = array_values(array_map(static function ($property): array {
                $parts = array_pad($property->getParts(), 7, '');
                $types = isset($property['TYPE']) ? $property['TYPE']->getParts() : [];
                return [
                    'postOfficeBox' => (string) $parts[0],
                    'extended' => (string) $parts[1],
                    'street' => (string) $parts[2],
                    'locality' => (string) $parts[3],
                    'region' => (string) $parts[4],
                    'postalCode' => (string) $parts[5],
                    'country' => (string) $parts[6],
                    'type' => isset($types[0]) ? (string) $types[0] : '',
                ];
            }, $card->select('ADR')));
        }
        if (count($card->select('CATEGORIES')) > 0) {
            $item['categories'] = [];
            foreach ($card->select('CATEGORIES') as $property) {
                foreach ($property->getParts() as $category) {
                    $item['categories'][] = (string) $category;
                }
            }
        }
        $item['properties'] = [];
        foreach ($card->children() as $property) {
            $item['properties'][] = [
                'name' => $property->name,
                'group' => $property->group,
                'value' => $property->getValue(),
                'serialized' => $property->serialize(),
            ];
        }
        $item['vcard'] = $card->serialize();
        return $item;
    }
}
