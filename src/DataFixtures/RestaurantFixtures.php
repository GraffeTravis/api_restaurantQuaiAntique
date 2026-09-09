<?php

namespace App\DataFixtures;

use App\Entity\{Restaurant, User};
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Exception;
use Faker;

class RestaurantFixtures extends Fixture implements DependentFixtureInterface
{
    /** @throws Exception */
    public function load(ObjectManager $manager): void
    {
        $faker = Faker\Factory::create();

        for ($i = 1; $i <= 20; $i++) {
            /** @var User $owner */
            $owner = $this->getReference('user' . $i, User::class); // ✅ user $i pour restaurant $i

            $restaurant = (new Restaurant())
                ->setName($faker->company())
                ->setDescription($faker->text())
                ->setAmOpeningTime([])
                ->setPmOpeningTime([])
                ->setMaxGuest(random_int(10, 50))
                ->setCreatedAt(new DateTimeImmutable())
                ->setOwner($owner);

            $manager->persist($restaurant);
            $this->addReference("restaurant" . $i, $restaurant);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}