<?php

namespace App\DataFixtures;

use App\Entity\Restaurant;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Exception;

class RestaurantFixtures extends Fixture
{
    /** @throws Exception */
    public function load(ObjectManager $manager): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $restaurant = (new Restaurant())
                ->setName("restaurant n°$i")
                ->setDescription("Description restaurant n°$i")
                ->setAmOpeningTime([])
                ->setPmOpeningTime([])
                ->setCreatedAt(new DateTimeImmutable());

            $manager->persist($restaurant);
            $this->addReference("restaurant$i", $restaurant);
        }
        $manager->flush();
    }
}