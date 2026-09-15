<?php

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Food;
use App\Entity\FoodCategory;
use App\Entity\Menu;
use App\Entity\MenuCategory;
use App\Entity\Restaurant;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CarteFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $categories = [
            'entrees' => 'Entrées',
            'plats' => 'Plats',
            'desserts' => 'Desserts',
        ];

        foreach ($categories as $reference => $title) {
            $category = (new Category())
                ->setTitle($title)
                ->setCreatedAt(new DateTimeImmutable());

            $manager->persist($category);
            $this->addReference('category_' . $reference, $category);
        }

        $foods = [
            ['Tartare de truite', 'Truite de Savoie, herbes fraîches et pickles maison.', 18, ['entrees']],
            ['Ravioles forestières', 'Ravioles gratinées, crème aux champignons et tomme fondue.', 24, ['plats']],
            ['Filet de féra', 'Féra du lac, légumes de saison et jus citronné.', 28, ['plats']],
            ['Tarte myrtille', 'Pâte sablée, crème légère et myrtilles de montagne.', 11, ['desserts']],
        ];

        foreach ($foods as [$title, $description, $price, $categoryKeys]) {
            $food = (new Food())
                ->setTitle($title)
                ->setDescription($description)
                ->setPrice($price)
                ->setCreatedAt(new DateTimeImmutable());

            $manager->persist($food);

            foreach ($categoryKeys as $categoryKey) {
                $foodCategory = (new FoodCategory())
                    ->setFood($food)
                    ->setCategory($this->getReference('category_' . $categoryKey, Category::class));

                $manager->persist($foodCategory);
            }
        }

        /** @var Restaurant $restaurant */
        $restaurant = $this->getReference('restaurant', Restaurant::class);

        $menus = [
            ['Menu Découverte', 'Entrée, plat et dessert autour des produits de Savoie.', 42, ['entrees', 'plats', 'desserts']],
            ['Menu du Midi', 'Plat du marché et dessert maison, servi du mardi au vendredi.', 29, ['plats', 'desserts']],
        ];

        foreach ($menus as [$title, $description, $price, $categoryKeys]) {
            $menu = (new Menu())
                ->setTitle($title)
                ->setDescription($description)
                ->setPrice($price)
                ->setRestaurant($restaurant)
                ->setCreatedAt(new DateTimeImmutable());

            $manager->persist($menu);

            foreach ($categoryKeys as $categoryKey) {
                $menuCategory = (new MenuCategory())
                    ->setMenu($menu)
                    ->setCategory($this->getReference('category_' . $categoryKey, Category::class));

                $manager->persist($menuCategory);
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [RestaurantFixtures::class];
    }
}
