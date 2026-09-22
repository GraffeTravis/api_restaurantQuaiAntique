<?php

namespace App\Service;

use App\Entity\Restaurant;

final class ServiceSchedule
{
    public static function valid(mixed $am, mixed $pm): bool
    {
        foreach ([$am, $pm] as $range) {
            if ($range === []) {
                continue;
            }
            if (!is_array($range) || !array_is_list($range) || count($range) !== 2) {
                return false;
            }
            foreach ($range as $hour) {
                if (!is_string($hour) || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $hour)) {
                    return false;
                }
            }
            $start = ((int) substr($range[0], 0, 2)) * 60 + (int) substr($range[0], 3, 2);
            $end = ((int) substr($range[1], 0, 2)) * 60 + (int) substr($range[1], 3, 2);
            if ($end - $start !== 120 || $start % 15 !== 0) {
                return false;
            }
        }

        return $am === [] || $pm === [] || $am[1] < $pm[0];
    }

    public static function rangeAt(Restaurant $restaurant, \DateTimeInterface $hour): ?array
    {
        $am = $restaurant->getAmOpeningTime();
        $pm = $restaurant->getPmOpeningTime();
        if (!self::valid($am, $pm)) {
            return null;
        }
        foreach ([$am, $pm] as $range) {
            if ($range !== [] && $hour->format('H:i') >= $range[0] && $hour->format('H:i') <= $range[1]) {
                return $range;
            }
        }

        return null;
    }

    public static function isFuture(\DateTimeInterface $date, \DateTimeInterface $hour): bool
    {
        $zone = new \DateTimeZone('Europe/Paris');
        $arrival = new \DateTimeImmutable($date->format('Y-m-d').' '.$hour->format('H:i'), $zone);

        return $arrival > new \DateTimeImmutable('now', $zone);
    }
}
