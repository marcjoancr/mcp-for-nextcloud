<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Deck\Db\Card;

class CardDate {
    private const DATE_FORMAT = 'Y-m-d';
    /**
     * The instant a `YYYY-MM-DD` written by the tools stands for: midnight of that day for the user.
     *
     * This is an account-local presentation value. Native persistence must use
     * {@see CardDueDate::forDeck()}, since Deck discards offsets in its datetime binding.
     *
     * @param string $day Validated `YYYY-MM-DD`.
     * @param \DateTimeZone $zone Timezone of the user.
     * @return string ISO 8601 with the user's offset, e.g. `2026-10-01T00:00:00-03:00`.
     */
    public static function localMidnight(string $day, \DateTimeZone $zone): string {
        return (new \DateTimeImmutable($day . ' 00:00:00', $zone))->format(\DateTimeInterface::ATOM);
    }

    /**
     * Due date of a card as the day the user sees, or null when it has none.
     *
     * @param Card $card card to read; Deck stores duedate as a datetime column, but an entity not yet hydrated may still hold the raw string, which is treated as a day
     * @param \DateTimeZone $zone Timezone of the user.
     * @return string|null Day in `YYYY-MM-DD`, or null.
     */
    public static function dueDay(Card $card, \DateTimeZone $zone): ?string {
        $duedate = $card->getDuedate();
        if ($duedate instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($duedate)->setTimezone($zone)->format(self::DATE_FORMAT);
        }
        return is_string($duedate) && $duedate !== '' ? $duedate : null;
    }

    /**
     * The fields a write can change, as the plan shows them.
     *
     * @param \OCA\Deck\Db\Card $card Card as the capability read it.
     * @param \DateTimeZone|null $zone Zone the due date is shown in, or null to keep the stored one.
     * @return array{title: string, description: string, duedate: string|null}
     */
    public static function fields(Card $card, ?\DateTimeZone $zone = null): array {
        return [
            'title' => (string) $card->getTitle(),
            'description' => (string) $card->getDescription(),
            'duedate' => self::instant($card->getDuedate(), $zone),
        ];
    }

    /**
     * @param mixed $value Value of a Deck `datetime` column.
     * @param \DateTimeZone|null $zone Zone the instant is shown in, or null to keep the stored one.
     * @return string|null ISO 8601 instant, or null when the card has no date.
     */
    public static function instant(mixed $value, ?\DateTimeZone $zone = null): ?string {
        if (!$value instanceof \DateTimeInterface) {
            return null;
        }
        return ($zone === null ? $value : \DateTimeImmutable::createFromInterface($value)->setTimezone($zone))->format(\DateTimeInterface::ATOM);
    }

    /**
     * Canonical account-local instant of a supported deadline, used for plan comparison.
     *
     * @param string|null $day Supported date or date-time, or null to clear the deadline.
     * @param \DateTimeZone $zone Timezone of the caller.
     * @return string|null ISO 8601 instant in the caller's zone, or null.
     */
    public static function day(?string $day, \DateTimeZone $zone): ?string {
        return CardDueDate::parse($day, $zone)?->setTimezone($zone)->format(\DateTimeInterface::ATOM);
    }
}
