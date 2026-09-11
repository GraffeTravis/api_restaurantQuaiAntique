<?php

namespace App\DataFixtures;

use App\Entity\{Restaurant, User};
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use App\DataFixtures\UserFixtures;
use Exception;
use Faker;

class RestaurantFixtures extends Fixture implements DependentFixtureInterface
{
    /** @throws Exception */
    public function load(ObjectManager $manager): void
    {

            /** @var Restaurant $owner */
            $owner = $this->getReference('admin', User::class);

            $restaurant = (new Restaurant())
                ->setName("Quai Antique")
                ->setDescription("Le Quai Antique, restaurant d'exeption")
                ->setAmOpeningTime([])
                ->setPmOpeningTime([])
                ->setMaxGuest(random_int(10, 50))
                ->setCreatedAt(new DateTimeImmutable())
                ->setOwner($owner);

        $manager->persist($restaurant);
        $this->addReference('restaurant', $restaurant);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}