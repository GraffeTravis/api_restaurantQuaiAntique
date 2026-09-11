<?php

namespace App\DataFixtures;

use App\Entity\{Picture, Restaurant};
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Exception;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

class PictureFixtures extends Fixture implements DependentFixtureInterface
{
    /** @throws Exception */
    public function load(ObjectManager $manager): void
    {
        for ($i = 1; $i <= 20; $i++) {
            
            /** @var Restaurant $restaurant */
            $restaurant = $this->getReference('restaurant', Restaurant::class);

            $picture = (new Picture())
                ->setTitle("Image n°$i")
                ->setSlug("slug-article-title n°$i")
                ->setRestaurant($restaurant)
                ->setCreatedAt(new DateTimeImmutable());

            $manager->persist($picture);
        }
        $manager->flush();
    }

    public function getDependencies(): array
    {

        return [RestaurantFixtures::class];

    }
}