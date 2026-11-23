<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Appointments;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Read-only projection of the stores of the two Calendar releases admitted by AppointmentGateway. */
final class AppointmentBookingReader {
    public function __construct(
        private AppointmentGateway $gateway,
        private IDBConnection $database,
        private EventDates $dates,
    ) {}

    public function list(array $arguments, string $actor): array {
        $from = $this->dates->parse($arguments['from'], 'from')->getTimestamp();
        $to = $this->dates->parse($arguments['to'], 'to')->getTimestamp();
        if ($from >= $to || $to - $from > 93 * 86400) {
            throw new ToolFailure('Select an increasing booking date range of at most 93 days.');
        }
        $limit = $arguments['limit'] ?? 50;
        $offset = $arguments['offset'] ?? 0;
        if (!is_int($limit) || $limit < 1 || $limit > 100 || !is_int($offset) || $offset < 0 || $offset > 10000) {
            throw new ToolFailure('Select a valid booking page.');
        }
        $calendars = $this->gateway->bookingCalendars($actor);
        if ($calendars === []) {
            return ['bookings' => [], 'next_offset' => null];
        }

        // The native BookingMapper can only find a single booking by token. Bound the read by actor,
        // scoped calendar URI and dates in SQL, and project neither booking tokens nor contact addresses.
        $query = $this->database->getQueryBuilder();
        $query->select('b.id', 'b.appt_config_id', 'b.start', 'b.end', 'b.confirmed', 'b.display_name', 'c.target_calendar_uri')
            ->selectAlias('c.name', 'appointment_name')
            ->from('calendar_appt_bookings', 'b')
            ->innerJoin('b', 'calendar_appt_configs', 'c', $query->expr()->eq('c.id', 'b.appt_config_id'))
            ->where($query->expr()->eq('c.user_id', $query->createNamedParameter($actor)))
            ->andWhere($query->expr()->in('c.target_calendar_uri', $query->createNamedParameter(array_keys($calendars), IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($query->expr()->gte('b.start', $query->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->lt('b.start', $query->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
            ->orderBy('b.start', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit + 1);
        if (isset($arguments['appointment_id'])) {
            $query->andWhere($query->expr()->eq('c.id', $query->createNamedParameter($arguments['appointment_id'], IQueryBuilder::PARAM_INT)));
        }

        $result = $query->executeQuery();
        try {
            $bookings = [];
            while ($row = $result->fetch()) {
                $bookings[] = [
                    'id' => (int)$row['id'],
                    'appointment_id' => (int)$row['appt_config_id'],
                    'appointment_name' => (string)$row['appointment_name'],
                    'calendar' => $calendars[$row['target_calendar_uri']]->path,
                    'start' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['start']),
                    'end' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['end']),
                    'status' => in_array($row['confirmed'], [true, 1, '1', 't'], true) ? 'confirmed' : 'pending',
                    'guest_name' => (string)$row['display_name'],
                ];
            }
        } finally {
            $result->closeCursor();
        }
        return [
            'bookings' => array_slice($bookings, 0, $limit),
            'next_offset' => count($bookings) > $limit ? $offset + $limit : null,
        ];
    }
}
