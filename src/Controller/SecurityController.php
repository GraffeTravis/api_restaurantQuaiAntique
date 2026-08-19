<?php

namespace App\Controller;

use OpenApi\Attributes as OA;
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
        #[OA\Post(
            path: '/api/registration',
            summary: "Inscription d'un nouvel utilisateur",
            requestBody: new OA\RequestBody(
                required: true,
                description: "Données de l'utilisateur à inscrire",
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'email', type: 'string', example: 'adresse@email.com'),
                        new OA\Property(property: 'password', type: 'string', example: 'Mot de passe'),
                    ]
                )
            ),
            responses: [
                new OA\Response(
                    response: 201,
                    description: 'Utilisateur inscrit avec succès',
                    content: new OA\JsonContent(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'user', type: 'string', example: "Nom d'utilisateur"),
                            new OA\Property(property: 'apiToken', type: 'string', example: '31a023e212f116124a36af14ea0c1c3806eb9378'),
                            new OA\Property(
                                property: 'roles',
                                type: 'array',
                                items: new OA\Items(type: 'string', example: 'ROLE_USER')
                            ),
                        ]
                    )
                )
            ]
        )]

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
    
        #[OA\Post(
            path: '/api/login',
            summary: 'Connecter un utilisateur',
            requestBody: new OA\RequestBody(
                required: true,
                description: "Données de l'utilisateur pour se connecter",
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'username', type: 'string', example: 'adresse@email.com'),
                        new OA\Property(property: 'password', type: 'string', example: 'Mot de passe'),
                    ]
                )
            ),
            responses: [
                new OA\Response(
                    response: 200,
                    description: 'Connexion réussie',
                    content: new OA\JsonContent(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'user', type: 'string', example: "Nom d'utilisateur"),
                            new OA\Property(property: 'apiToken', type: 'string', example: '31a023e212f116124a36af14ea0c1c3806eb9378'),
                            new OA\Property(
                                property: 'roles',
                                type: 'array',
                                items: new OA\Items(type: 'string', example: 'ROLE_USER')
                            ),
                        ]
                    )
                )
            ]
        )]

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

