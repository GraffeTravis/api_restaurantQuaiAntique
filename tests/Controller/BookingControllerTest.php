<?php

namespace App\Tests\Controller;

use App\Controller\BookingController;
use App\Entity\Booking;
use App\Entity\Restaurant;
use App\Entity\User;
use DateTime;
use PHPUnit\Framework\TestCase;

class BookingControllerTest extends TestCase
{
    public function testRestaurantIsOpenDuringDeclaredService(): void
    {
        $controller = $this->createController();
        $restaurant = $this->createRestaurant();

        self::assertTrue($this->isOpenAt(
            $controller,
            $restaurant,
            new DateTime('2026-09-15'),
            new DateTime('19:30')
        ));
    }

    public function testRestaurantIsClosedOnMonday(): void
    {
        $controller = $this->createController();
        $restaurant = $this->createRestaurant();

        self::assertFalse($this->isOpenAt(
            $controller,
            $restaurant,
            new DateTime('2026-09-14'),
            new DateTime('19:30')
        ));
    }

    public function testRestaurantIsClosedOutsideServiceHours(): void
    {
        $controller = $this->createController();
        $restaurant = $this->createRestaurant();

        self::assertFalse($this->isOpenAt(
            $controller,
            $restaurant,
            new DateTime('2026-09-15'),
            new DateTime('15:00')
        ));
    }

    public function testBookingCanBeManagedByOwnerOrAdminOnly(): void
    {
        $controller = $this->createController();
        $owner = (new User())->setEmail('client@example.test');
        $admin = (new User())
            ->setEmail('admin@example.test')
            ->setRoles(['ROLE_ADMIN']);
        $otherClient = (new User())->setEmail('other@example.test');
        $booking = (new Booking())->setClient($owner);

        self::assertTrue($this->canManageBooking($controller, $booking, $owner));
        self::assertTrue($this->canManageBooking($controller, $booking, $admin));
        self::assertFalse($this->canManageBooking($controller, $booking, $otherClient));
    }

    private function createController(): BookingController
    {
        return (new \ReflectionClass(BookingController::class))->newInstanceWithoutConstructor();
    }

    private function createRestaurant(): Restaurant
    {
        return (new Restaurant())
            ->setName('Quai Antique')
            ->setDescription('Restaurant de test')
            ->setMaxGuest(50)
            ->setOwner(new User())
            ->setCreatedAt(new \DateTimeImmutable())
            ->setAmOpeningTime(['12:00', '14:00'])
            ->setPmOpeningTime(['19:00', '21:00']);
    }

    private function isOpenAt(
        BookingController $controller,
        Restaurant $restaurant,
        DateTime $date,
        DateTime $hour
    ): bool {
        $isOpenAt = \Closure::bind(
            function (Restaurant $restaurant, DateTime $date, DateTime $hour): bool {
                return $this->isOpenAt($restaurant, $date, $hour);
            },
            $controller,
            BookingController::class
        );

        return $isOpenAt($restaurant, $date, $hour);
    }

    private function canManageBooking(
        BookingController $controller,
        Booking $booking,
        User $user
    ): bool {
        $canManageBooking = \Closure::bind(
            function (Booking $booking, User $user): bool {
                return $this->canManageBooking($booking, $user);
            },
            $controller,
            BookingController::class
        );

        return $canManageBooking($booking, $user);
    }
}
