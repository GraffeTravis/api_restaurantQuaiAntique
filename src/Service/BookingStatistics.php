<?php

namespace App\Service;

use App\Repository\BookingAnalyticsRepository;
use Doctrine\DBAL\Connection;

final readonly class BookingStatistics
{
    public function __construct(private BookingAnalyticsRepository $repository, private Connection $connection)
    {
    }

    public function calculate(BookingStatisticsFilter $filter): array
    {
        // Read backlog first so a response cannot claim freshness for events still being processed.
        $queue = $this->connection->fetchAssociative(<<<'SQL'
            SELECT count(*) FILTER (WHERE queue_name = 'analytics') AS pending,
                count(*) FILTER (WHERE queue_name = 'analytics_failed') AS failed
            FROM messenger_messages
            WHERE queue_name IN ('analytics', 'analytics_failed')
                AND CASE WHEN queue_name IN ('analytics', 'analytics_failed')
                    THEN (body::jsonb->'snapshot'->>'restaurantId')::int END = ?
            SQL, [$filter->restaurantId]);
        $data = $this->repository->aggregate($filter);
        $totals = $data['totals'][0] ?? ['bookings' => 0, 'guests' => 0];
        $series = array_column($data['series'], null, '_id');
        $filled = [];
        for ($day = $filter->from; $day <= $filter->to; $day = $day->modify('+1 day')) {
            $key = $filter->bucket($day);
            $filled[$key] = ['date' => $key, 'bookings' => $series[$key]['bookings'] ?? 0, 'guests' => $series[$key]['guests'] ?? 0];
        }
        $map = static fn (array $rows, string $name): array => array_map(static fn (array $row): array => [
            $name => $row['_id'], 'bookings' => $row['bookings'], 'guests' => $row['guests'],
        ], $rows);
        $slots = $data['slots'];
        usort($slots, static fn ($a, $b) => ($b['bookings'] <=> $a['bookings']) ?: strcmp($a['_id'], $b['_id']));

        return [
            'filters' => ['from' => $filter->from->format('Y-m-d'), 'to' => $filter->to->format('Y-m-d'),
                'restaurantId' => $filter->restaurantId, 'granularity' => $filter->granularity, 'timezone' => BookingStatisticsFilter::TIMEZONE],
            'totals' => ['bookings' => $totals['bookings'], 'guests' => $totals['guests'],
                'averageGuests' => $totals['bookings'] ? round($totals['guests'] / $totals['bookings'], 2) : 0],
            'series' => array_values($filled), 'services' => $map($data['services'], 'service'),
            'weekdays' => $map($data['weekdays'], 'day'), 'slots' => $map($slots, 'hour'),
            'synchronization' => ['lastSyncedAt' => $this->repository->lastSyncedAt($filter->restaurantId),
                'pending' => (int) $queue['pending'], 'failed' => (int) $queue['failed']],
        ];
    }
}
