<?php

namespace App\Command;

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

        $this->manager->flush();

        $output->writeln('<info>Initialisation production terminee.</info>');

        return Command::SUCCESS;
    }
}
