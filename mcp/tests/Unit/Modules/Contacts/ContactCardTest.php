<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Contacts;

use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

final class ContactCardTest extends TestCase
{
    public function testPatchesCategoriesAndStructuredNameAndAddressWithoutChangingVCardVersion(): void
    {
        $cards = new ContactCard();
        $original = Reader::read("BEGIN:VCARD\r\nVERSION:4.0\r\nUID:one\r\nFN:Ana Silva\r\nN:Silva;Ana;;;\r\nADR;TYPE=HOME:;;Rua Um;Lisboa;;1000;Portugal\r\nCATEGORIES:Friends,Colleagues\r\nX-CUSTOM:keep\r\nEND:VCARD\r\n");

        $patched = $cards->patch($original, [
            'familyName' => 'Costa',
            'givenName' => 'Ana',
            'addresses' => [[
                'type' => 'HOME',
                'street' => 'Rua Dois',
                'locality' => 'Lisboa',
                'postalCode' => '1000',
                'country' => 'Portugal',
            ]],
            'categories' => ['Friends', 'Work'],
            'birthday' => '2001-02-03',
        ]);
        $serialized = $patched->serialize();
        $item = $cards->item($patched);

        self::assertSame('4.0', (string) $patched->VERSION);
        self::assertStringContainsString('X-CUSTOM:keep', $serialized);
        self::assertSame(['Friends', 'Work'], $item['categories']);
        self::assertSame('Costa', $item['familyName']);
        self::assertSame('Ana', $item['givenName']);
        self::assertSame('Rua Dois', $item['addresses'][0]['street']);
        self::assertSame('HOME', $item['addresses'][0]['type']);
        self::assertSame('2001-02-03', $item['birthday'] ?? null);
    }

    public function testSupportsBirthdayAndPreferredEmailForVCardFour(): void
    {
        $cards = new ContactCard();
        $original = Reader::read("BEGIN:VCARD\r\nVERSION:4.0\r\nUID:one\r\nFN:Ana\r\nBDAY:2000-01-01\r\nEMAIL;TYPE=WORK;PREF=2:work@example.com\r\nEMAIL;TYPE=HOME:home@example.com\r\nEND:VCARD\r\n");

        $patched = $cards->patch($original, ['birthday' => '2001-02-03', 'preferredEmail' => 'home@example.com']);
        $item = $cards->item($patched);
        $emails = array_values($patched->select('EMAIL'));

        self::assertSame('4.0', (string) $patched->VERSION);
        self::assertSame('2001-02-03', $item['birthday']);
        self::assertSame('home@example.com', $item['preferredEmail']);
        self::assertSame('1', (string) $emails[1]['PREF']);
        self::assertArrayNotHasKey('PREF', $emails[0]);
    }

    public function testUsesTypePrefForVCardThreeWithoutDroppingOtherEmailTypes(): void
    {
        $cards = new ContactCard();
        $original = Reader::read("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:one\r\nFN:Ana\r\nEMAIL;TYPE=WORK,PREF:work@example.com\r\nEMAIL;TYPE=HOME:home@example.com\r\nEND:VCARD\r\n");

        $patched = $cards->patch($original, ['preferredEmail' => 'home@example.com']);
        $emails = array_values($patched->select('EMAIL'));
        $types = array_map(static fn($property): array => array_map('strtoupper', $property['TYPE']->getParts()), $emails);

        self::assertSame([['WORK'], ['HOME', 'PREF']], $types);
        self::assertSame('home@example.com', $cards->item($patched)['preferredEmail']);
    }

    public function testDuplicatePreservesCardPropertiesAndVersionButGetsNewUid(): void
    {
        $cards = new ContactCard();
        $original = Reader::read("BEGIN:VCARD\r\nVERSION:4.0\r\nUID:original\r\nFN:Ana\r\nX-CUSTOM:keep\r\nEND:VCARD\r\n");

        $copy = $cards->duplicate($original);

        self::assertSame('4.0', (string) $copy->VERSION);
        self::assertNotSame('original', (string) $copy->UID);
        self::assertSame('keep', (string) $copy->{'X-CUSTOM'});
        self::assertSame('original', (string) $original->UID);
    }

    public function testCreatesStructuredVCard30WithEmptyMultiValueFields(): void
    {
        $cards = new ContactCard();
        $card = $cards->create([
            'name' => 'Ana Silva',
            'familyName' => 'Silva',
            'givenName' => 'Ana',
            'addresses' => [],
            'categories' => [],
        ]);

        self::assertSame('3.0', (string) $card->VERSION);
        self::assertSame(['Silva', 'Ana', '', '', ''], $card->N->getParts());
        self::assertArrayNotHasKey('addresses', $cards->item($card));
        self::assertArrayNotHasKey('categories', $cards->item($card));
    }
}
