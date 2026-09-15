<?php

namespace App\Tests\Entity;

use App\Entity\Booking;
use App\Entity\Restaurant;
use App\Entity\User;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RestaurantTest extends TestCase
{
    public static function provideRestaurantName(): \Generator
    {
        yield ['Quai Antique'];
        yield ['Le Bistrot'];
        yield ['L\'Olivier'];
    }

    #[DataProvider('provideRestaurantName')]
    public function testRestaurantNameSetter(string $name): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName($name);

        self::assertSame($name, $restaurant->getName());
    }

    public function testRestaurantMaxGuestSetter(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setMaxGuest(50);

        self::assertSame(50, $restaurant->getMaxGuest());
    }

    public function testRestaurantCountsGuestsForAGivenSlot(): void
    {
        $restaurant = $this->createRestaurant(10);
        $date = new DateTime('2026-09-15');
        $matchingBooking = (new Booking())
            ->setGuestNumber(4)
            ->setOrderDate($date)
            ->setOrderHour(new DateTime('19:30'));
        $otherBooking = (new Booking())
            ->setGuestNumber(8)
            ->setOrderDate($date)
            ->setOrderHour(new DateTime('20:00'));

        $restaurant->addBooking($matchingBooking);
        $restaurant->addBooking($otherBooking);

        self::assertSame(4, $restaurant->getBookedGuestsForSlot(
            DateTimeImmutable::createFromMutable($date),
            '19:30'
        ));
        self::assertTrue($restaurant->hasAvailability(
            6,
            DateTimeImmutable::createFromMutable($date),
            '19:30'
        ));
        self::assertFalse($restaurant->hasAvailability(
            7,
            DateTimeImmutable::createFromMutable($date),
            '19:30'
        ));
    }

    private function createRestaurant(int $maxGuest): Restaurant
    {
        return (new Restaurant())
            ->setName('Quai Antique')
            ->setDescription('Restaurant de test')
            ->setMaxGuest($maxGuest)
            ->setOwner(new User())
            ->setCreatedAt(new DateTimeImmutable());
    }
}
