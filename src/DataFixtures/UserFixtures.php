<?php

namespace App\DataFixtures;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Exception;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class UserFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher)
    {
    }

    /** @throws Exception */
    public function load(ObjectManager $manager): void
    {
        // Utilisateurs clients de test
        for ($i = 1; $i <= 20; $i++) {
            $user = (new User())
                ->setUuid(Uuid::v6())
                ->setFirstName("Firstname $i")
                ->setLastName("Lastname $i")
                ->setEmail("email.$i@studi.fr")
                ->setCreatedAt(new DateTimeImmutable());

            $user->setPassword(
                $this->passwordHasher->hashPassword($user, 'password' . $i)
            );

            // Les utilisateurs créés par défaut sont des clients.
            $user->setRoles(['ROLE_USER']);

            $manager->persist($user);
            $this->addReference("user" . $i, $user);
        }

        // Compte administrateur de test.
        $admin = (new User())
            ->setUuid(Uuid::v6())
            ->setFirstName('Admin')
            ->setLastName('Quai Antique')
            ->setEmail('admin@quai-antique.local')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new DateTimeImmutable());

        $admin->setPassword(
            $this->passwordHasher->hashPassword($admin, 'AdminPassword123!')
        );

        $manager->persist($admin);
        $this->addReference('admin', $admin);

        $manager->flush();
    }
}