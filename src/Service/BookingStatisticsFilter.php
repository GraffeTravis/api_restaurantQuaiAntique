<?php

namespace App\Service;

final readonly class BookingStatisticsFilter
{
    public const TIMEZONE = 'Europe/Paris';

    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public int $restaurantId,
        public string $granularity,
    ) {
    }

    public static function fromQuery(array $query): self
    {
        $zone = new \DateTimeZone(self::TIMEZONE);
        $today = new \DateTimeImmutable('today', $zone);
        $dates = [];
        foreach (['from' => $today->modify('first day of this month'), 'to' => $today] as $key => $default) {
            $value = $query[$key] ?? $default->format('Y-m-d');
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone) : false;
            if (!$date || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 1900) {
                throw new \InvalidArgumentException('Dates invalides : utilisez le format YYYY-MM-DD.');
            }
            $dates[$key] = $date;
        }
        if ($dates['from'] > $dates['to'] || $dates['from']->diff($dates['to'])->days > 365) {
            throw new \InvalidArgumentException('La période doit être ordonnée et limitée à 366 jours inclus.');
        }
        $restaurantId = filter_var($query['restaurantId'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        $granularity = $query['granularity'] ?? 'day';
        if (!$restaurantId || !in_array($granularity, ['day', 'week', 'month'], true)) {
            throw new \InvalidArgumentException('Restaurant ou granularité invalide (day, week, month).');
        }

        return new self($dates['from'], $dates['to'], $restaurantId, $granularity);
    }

    public function bucket(\DateTimeImmutable $date): string
    {
        return match ($this->granularity) {
            'week' => $date->modify('monday this week')->format('Y-m-d'),
            'month' => $date->format('Y-m-01'),
            default => $date->format('Y-m-d'),
        };
    }
}
