<?php

namespace App\Tests\Service;

use App\Service\BookingStatisticsFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingStatisticsFilterTest extends TestCase
{
    #[DataProvider('invalidQueries')]
    public function testRejectsInvalidFilters(array $query): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BookingStatisticsFilter::fromQuery($query);
    }

    public static function invalidQueries(): iterable
    {
        yield [['from' => '2026-02-30']];
        yield [['from' => ['2026-01-01']]];
        yield [['restaurantId' => ['1']]];
        yield [['restaurantId' => '0']];
        yield [['restaurantId' => '1.5']];
        yield [['granularity' => ['$gt' => '']]];
        yield [['granularity' => 'year']];
        yield [['from' => '2026-02-01', 'to' => '2026-01-01']];
        yield [['from' => '2024-01-01', 'to' => '2025-01-01']];
    }

    public function testInclusiveLeapYearAndIsoWeekAcrossYearBoundary(): void
    {
        $filter = BookingStatisticsFilter::fromQuery(['from' => '2024-01-01', 'to' => '2024-12-31', 'granularity' => 'week']);
        self::assertSame('Europe/Paris', $filter->from->getTimezone()->getName());
        self::assertSame('2024-12-30', $filter->bucket(new \DateTimeImmutable('2025-01-01')));
    }
}
