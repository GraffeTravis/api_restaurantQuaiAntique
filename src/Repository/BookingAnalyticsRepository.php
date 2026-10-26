<?php

namespace App\Repository;

use App\Service\BookingStatisticsFilter;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BookingAnalyticsRepository
{
    private ?Collection $collection = null;
    private bool $indexesReady = false;

    public function __construct(
        #[Autowire('%env(MONGODB_URI)%')] private readonly string $uri,
        #[Autowire('%env(MONGODB_DATABASE)%')] private readonly string $database,
    ) {
    }

    private function collection(): Collection
    {
        // Lazy connection: booking writes and unrelated API routes never contact MongoDB.
        return $this->collection ??= (new Client($this->uri, [
            'serverSelectionTimeoutMS' => 2000, 'connectTimeoutMS' => 2000, 'socketTimeoutMS' => 5000,
            'w' => 'majority',
        ]))->selectCollection($this->database, 'booking_analytics', [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ]);
    }

    public function ensureIndexes(): void
    {
        if ($this->indexesReady) {
            return;
        }
        $this->collection()->createIndex(['restaurantId' => 1, 'deleted' => 1, 'date' => 1]);
        $this->collection()->createIndex(['restaurantId' => 1, 'syncedAt' => -1]);
        $this->indexesReady = true;
    }

    public function synchronize(array $snapshot): void
    {
        $this->ensureIndexes();
        $document = $snapshot + ['_id' => $snapshot['bookingId']];
        // The filter always uses _id alone: stale messages must not cause an upsert conflict.
        // Compare and replace in one atomic MongoDB operation, retaining deletion tombstones.
        $update = [['$replaceWith' => ['$cond' => [
            ['$lte' => [['$ifNull' => ['$version', 0]], $snapshot['version']]],
            ['$mergeObjects' => [['$literal' => $document], ['syncedAt' => '$$NOW']]],
            '$$ROOT',
        ]]]];
        try {
            $this->collection()->updateOne(['_id' => $snapshot['bookingId']], $update, ['upsert' => true]);
        } catch (BulkWriteException $exception) {
            if ($exception->getCode() !== 11000) {
                throw $exception;
            }
            // Another worker inserted this id concurrently; the version guard still applies.
            $this->collection()->updateOne(['_id' => $snapshot['bookingId']], $update);
        }
    }

    public function aggregate(BookingStatisticsFilter $filter): array
    {
        $sum = ['bookings' => ['$sum' => 1], 'guests' => ['$sum' => '$guests']];
        $group = static fn ($id): array => [
            ['$group' => ['_id' => $id] + $sum], ['$sort' => ['_id' => 1]],
        ];
        $date = ['$dateFromString' => ['dateString' => '$date', 'timezone' => BookingStatisticsFilter::TIMEZONE]];
        $bucket = ['$dateTrunc' => [
            'date' => $date, 'unit' => $filter->granularity, 'timezone' => BookingStatisticsFilter::TIMEZONE,
        ] + ($filter->granularity === 'week' ? ['startOfWeek' => 'monday'] : [])];
        $pipeline = [
            ['$match' => ['restaurantId' => $filter->restaurantId, 'deleted' => false,
                'date' => ['$gte' => $filter->from->format('Y-m-d'), '$lte' => $filter->to->format('Y-m-d')]]],
            ['$facet' => [
                'totals' => $group(null),
                'series' => $group(['$dateToString' => ['date' => $bucket, 'format' => '%Y-%m-%d', 'timezone' => BookingStatisticsFilter::TIMEZONE]]),
                'services' => $group('$service'),
                'weekdays' => $group(['$isoDayOfWeek' => ['date' => $date, 'timezone' => BookingStatisticsFilter::TIMEZONE]]),
                'slots' => $group('$hour'),
            ]],
        ];

        return $this->collection()->aggregate($pipeline, ['maxTimeMS' => 5000])->toArray()[0];
    }

    public function lastSyncedAt(int $restaurantId): ?string
    {
        $document = $this->collection()->findOne(['restaurantId' => $restaurantId], [
            'sort' => ['syncedAt' => -1], 'projection' => ['syncedAt' => 1], 'maxTimeMS' => 2000,
        ]);

        return isset($document['syncedAt']) ? $document['syncedAt']->toDateTime()->format(DATE_ATOM) : null;
    }
}
