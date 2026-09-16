<?php

namespace App\Command;

use App\Entity\Category;
use App\Entity\Food;
use App\Entity\FoodCategory;
use App\Entity\Menu;
use App\Entity\MenuCategory;
use App\Entity\Picture;
use App\Entity\Restaurant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed-production',
    description: 'Initialise les donnees minimales de production.'
)]
class SeedProductionCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $manager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Email du compte administrateur')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Mot de passe a utiliser si le compte admin doit etre cree')
            ->addOption('restaurant-name', null, InputOption::VALUE_REQUIRED, 'Nom du restaurant', 'Quai Antique')
            ->addOption('max-guest', null, InputOption::VALUE_REQUIRED, 'Capacite maximale du restaurant', '40');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $adminEmail = trim((string) (
            $input->getOption('admin-email')
            ?: $_ENV['INITIAL_ADMIN_EMAIL']
            ?? 'contact.travisgraffe@gmail.com'
        ));

        if ($adminEmail === '') {
            $output->writeln('<error>Admin email manquant.</error>');
            return Command::FAILURE;
        }

        $admin = $this->manager->getRepository(User::class)->findOneBy(['email' => $adminEmail]);

        if (!$admin instanceof User) {
            $plainPassword = (string) (
                $input->getOption('admin-password')
                ?: $_ENV['INITIAL_ADMIN_PASSWORD']
                ?? ''
            );

            if ($plainPassword === '') {
                $output->writeln('<error>Le compte admin n existe pas et aucun mot de passe initial n a ete fourni.</error>');
                return Command::FAILURE;
            }

            $admin = (new User())
                ->setEmail($adminEmail)
                ->setFirstName('Travis')
                ->setLastName('Graffe')
                ->setCreatedAt(new DateTimeImmutable())
                ->setGuestNumber(2);

            $admin->setPassword($this->passwordHasher->hashPassword($admin, $plainPassword));
            $this->manager->persist($admin);
            $output->writeln(sprintf('<info>Compte admin cree : %s</info>', $adminEmail));
        }

        $roles = $admin->getRoles();
        if (!in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_ADMIN';
            $admin->setRoles(array_values(array_unique($roles)));
            $output->writeln(sprintf('<info>Compte promu administrateur : %s</info>', $adminEmail));
        } else {
            $output->writeln(sprintf('<comment>Compte deja administrateur : %s</comment>', $adminEmail));
        }

        $restaurantName = trim((string) $input->getOption('restaurant-name'));
        $maxGuest = max(1, (int) $input->getOption('max-guest'));

        $restaurantRepository = $this->manager->getRepository(Restaurant::class);
        $restaurant = $restaurantRepository->findOneBy(['name' => $restaurantName])
            ?? $restaurantRepository->findOneBy([]);

        if (!$restaurant instanceof Restaurant) {
            $restaurant = (new Restaurant())->setCreatedAt(new DateTimeImmutable());
            $this->manager->persist($restaurant);
            $output->writeln(sprintf('<info>Restaurant cree : %s</info>', $restaurantName));
        } else {
            $restaurant->setUpdateAt(new DateTimeImmutable());
            $output->writeln(sprintf('<comment>Restaurant existant mis a jour : %s</comment>', $restaurant->getName()));
        }

        $restaurant
            ->setName($restaurantName)
            ->setDescription('Restaurant gastronomique savoyard mettant a l honneur les produits frais et la cuisine de saison.')
            ->setAmOpeningTime(['12:00', '14:00'])
            ->setPmOpeningTime(['19:00', '21:00'])
            ->setMaxGuest($maxGuest)
            ->setOwner($admin);

        $this->seedCarte($restaurant, $output);
        $this->seedGallery($restaurant, $output);

        $this->manager->flush();

        $output->writeln('<info>Initialisation production terminee.</info>');

        return Command::SUCCESS;
    }

    private function seedCarte(Restaurant $restaurant, OutputInterface $output): void
    {
        $categories = [
            'Entrées',
            'Plats',
            'Desserts',
        ];

        $categoryEntities = [];
        foreach ($categories as $title) {
            $categoryEntities[$title] = $this->findOrCreateCategory($title);
        }

        $foods = [
            [
                'title' => 'Tartare de truite de Savoie',
                'description' => 'Truite fraîche, herbes alpines, pickles maison et crème citronnée.',
                'price' => 18,
                'categories' => ['Entrées'],
            ],
            [
                'title' => 'Velouté de champignons',
                'description' => 'Champignons de saison, noisettes torréfiées et huile aux herbes.',
                'price' => 14,
                'categories' => ['Entrées'],
            ],
            [
                'title' => 'Filet de féra du lac',
                'description' => 'Poisson du lac, légumes de saison et jus beurre citron.',
                'price' => 28,
                'categories' => ['Plats'],
            ],
            [
                'title' => 'Ravioles forestières',
                'description' => 'Ravioles gratinées, crème aux champignons et tomme fondue.',
                'price' => 24,
                'categories' => ['Plats'],
            ],
            [
                'title' => 'Tarte fine aux myrtilles',
                'description' => 'Pâte croustillante, crème légère et myrtilles de montagne.',
                'price' => 11,
                'categories' => ['Desserts'],
            ],
            [
                'title' => 'Crémeux chocolat noir',
                'description' => 'Chocolat intense, biscuit cacao et éclats de noisettes.',
                'price' => 12,
                'categories' => ['Desserts'],
            ],
        ];

        foreach ($foods as $foodData) {
            $food = $this->findOrCreateFood($foodData['title'], $foodData['description'], $foodData['price']);
            foreach ($foodData['categories'] as $categoryTitle) {
                $this->linkFoodCategory($food, $categoryEntities[$categoryTitle]);
            }
        }

        $menus = [
            [
                'title' => 'Menu Découverte',
                'description' => 'Entrée, plat et dessert autour des produits de Savoie.',
                'price' => 42,
                'categories' => ['Entrées', 'Plats', 'Desserts'],
            ],
            [
                'title' => 'Menu du Midi',
                'description' => 'Plat du marché et dessert maison, servi du mardi au vendredi.',
                'price' => 29,
                'categories' => ['Plats', 'Desserts'],
            ],
        ];

        foreach ($menus as $menuData) {
            $menu = $this->findOrCreateMenu(
                $menuData['title'],
                $menuData['description'],
                $menuData['price'],
                $restaurant
            );

            foreach ($menuData['categories'] as $categoryTitle) {
                $this->linkMenuCategory($menu, $categoryEntities[$categoryTitle]);
            }
        }

        $output->writeln('<info>Carte de production initialisee.</info>');
    }

    private function findOrCreateCategory(string $title): Category
    {
        $category = $this->manager->getRepository(Category::class)->findOneBy(['title' => $title]);

        if (!$category instanceof Category) {
            $category = (new Category())
                ->setTitle($title)
                ->setCreatedAt(new DateTimeImmutable());

            $this->manager->persist($category);
        } else {
            $category->setUpdatedAt(new DateTimeImmutable());
        }

        return $category;
    }

    private function findOrCreateFood(string $title, string $description, int $price): Food
    {
        $food = $this->manager->getRepository(Food::class)->findOneBy(['title' => $title]);

        if (!$food instanceof Food) {
            $food = (new Food())->setCreatedAt(new DateTimeImmutable());
            $this->manager->persist($food);
        } else {
            $food->setUpdatedAt(new DateTimeImmutable());
        }

        $food
            ->setTitle($title)
            ->setDescription($description)
            ->setPrice($price);

        return $food;
    }

    private function findOrCreateMenu(string $title, string $description, int $price, Restaurant $restaurant): Menu
    {
        $menu = $this->manager->getRepository(Menu::class)->findOneBy(['title' => $title]);

        if (!$menu instanceof Menu) {
            $menu = (new Menu())->setCreatedAt(new DateTimeImmutable());
            $this->manager->persist($menu);
        } else {
            $menu->setUpdatedAt(new DateTimeImmutable());
        }

        $menu
            ->setTitle($title)
            ->setDescription($description)
            ->setPrice($price)
            ->setRestaurant($restaurant);

        return $menu;
    }

    private function linkFoodCategory(Food $food, Category $category): void
    {
        $existingLink = $this->manager->getRepository(FoodCategory::class)->findOneBy([
            'food' => $food,
            'category' => $category,
        ]);

        if ($existingLink instanceof FoodCategory) {
            return;
        }

        $this->manager->persist(
            (new FoodCategory())
                ->setFood($food)
                ->setCategory($category)
        );
    }

    private function linkMenuCategory(Menu $menu, Category $category): void
    {
        $existingLink = $this->manager->getRepository(MenuCategory::class)->findOneBy([
            'menu' => $menu,
            'category' => $category,
        ]);

        if ($existingLink instanceof MenuCategory) {
            return;
        }

        $this->manager->persist(
            (new MenuCategory())
                ->setMenu($menu)
                ->setCategory($category)
        );
    }

    private function seedGallery(Restaurant $restaurant, OutputInterface $output): void
    {
        $pictures = [
            [
                'title' => 'Soupe à l oignon gratinée',
                'slug' => 'soupe-oignon-gratinee',
                'fileName' => 'soupe-oignon.jpg',
            ],
            [
                'title' => 'Crêpes salées maison',
                'slug' => 'crepes-salees-maison',
                'fileName' => 'crepes-salees.jpg',
            ],
            [
                'title' => 'Filet de poisson et légumes',
                'slug' => 'filet-poisson-legumes',
                'fileName' => 'filet-poisson.jpg',
            ],
            [
                'title' => 'Poulet aux champignons',
                'slug' => 'poulet-aux-champignons',
                'fileName' => 'poulet-champignons.jpg',
            ],
            [
                'title' => 'Ratatouille de saison',
                'slug' => 'ratatouille-de-saison',
                'fileName' => 'ratatouille.jpg',
            ],
            [
                'title' => 'Tarte tatin',
                'slug' => 'tarte-tatin',
                'fileName' => 'tarte-tatin.jpg',
            ],
            [
                'title' => 'Crème brûlée',
                'slug' => 'creme-brulee',
                'fileName' => 'creme-brulee.jpg',
            ],
            [
                'title' => 'Tarte citron meringuée',
                'slug' => 'tarte-citron-meringuee',
                'fileName' => 'tarte-citron-meringuee.jpg',
            ],
        ];

        foreach ($pictures as $pictureData) {
            $picture = $this->manager->getRepository(Picture::class)->findOneBy([
                'slug' => $pictureData['slug'],
                'restaurant' => $restaurant,
            ]);

            if (!$picture instanceof Picture) {
                $picture = (new Picture())->setCreatedAt(new DateTimeImmutable());
                $this->manager->persist($picture);
            } else {
                $picture->setUpdatedAt(new DateTimeImmutable());
            }

            $picture
                ->setTitle($pictureData['title'])
                ->setSlug($pictureData['slug'])
                ->setFileName($pictureData['fileName'])
                ->setRestaurant($restaurant);
        }

        $output->writeln('<info>Galerie de production initialisee.</info>');
    }
}
