<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('api', name: 'app_api_')]
class SecurityController extends AbstractController
{

    public function __construct(private EntityManagerInterface $manager, private SerializerInterface $serializer)

    {

    }

    #[Route('/registration', name: 'registration', methods: 'POST')]

    public function register(Request $request, UserPasswordHasherInterface $passwordHasher): JsonResponse

    {

        $user = $this->serializer->deserialize($request->getContent(), User::class, 'json');

        $user->setPassword($passwordHasher->hashPassword($user, $user->getPassword()));

        $user->setCreatedAt(new DateTimeImmutable());

        $this->manager->persist($user);

        $this->manager->flush();

        return new JsonResponse(

            ['user'  => $user->getUserIdentifier(), 'apiToken' => $user->getApiToken(), 'roles' => $user->getRoles()],

            Response::HTTP_CREATED

        );

    }

    #[Route('/login', name: 'login', methods: 'POST')]

    public function login(#[CurrentUser] ?User $user): JsonResponse

    {

        if (null === $user) {

            return new JsonResponse(['message' => 'Missing credentials'], Response::HTTP_UNAUTHORIZED);

        }

        return new JsonResponse([

            'user'  => $user->getUserIdentifier(),

            'apiToken' => $user->getApiToken(),

            'roles' => $user->getRoles(),

        ]);

    }

}

