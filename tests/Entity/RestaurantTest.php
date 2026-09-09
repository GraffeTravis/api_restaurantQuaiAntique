<?php

namespace App\Tests\Entity;

use App\Entity\Restaurant;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class UserTest extends TestCase
{
    public static function createRestaurant(): \Generator
    {
        yield ['Quai Antique'];
        yield ['Le Bistrot'];
        yield ['L\'Olivier'];
    }

    #[DataProvider('createRestaurant')]
    public function testRestaurantNameSetter(string $name): void
    {
        $restaurant = new \App\Entity\Restaurant();
        $restaurant->setName($name);
        $restaurant->setMaxGuest(50);
        $restaurant->setOwner("Timéo");

        $this->assertSame($name, $restaurant->getName());
    }

    public function testRestaurantMaxGuestSetter(): void
    {
        $restaurant = new \App\Entity\Restaurant();
        $restaurant->setMaxGuest(50);

        $this->assertSame(50, $restaurant->getMaxGuest());
    }
}