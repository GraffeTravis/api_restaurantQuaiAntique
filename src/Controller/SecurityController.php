<?php

namespace App\Controller;

use OpenApi\Attributes as OA;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api', name: 'app_api_')]
#[OA\Tag(name: 'Authentification', description: 'Authentification des utilisateurs')]
class SecurityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private SerializerInterface $serializer
    ) {
    }

    #[Route('/registration', name: 'registration', methods: ['POST'])]
    #[OA\Post(
        path: '/api/registration',
        summary: "Inscription d'un nouvel utilisateur",
        requestBody: new OA\RequestBody(
            required: true,
            description: "Données de l'utilisateur à inscrire",
            content: new OA\JsonContent(
                type: 'object',
                required: ['email', 'password'],
                properties: [
                    new OA\Property(
                        property: 'email',
                        type: 'string',
                        example: 'adresse@email.com'
                    ),
                    new OA\Property(
                        property: 'password',
                        type: 'string',
                        example: 'Mot de passe'
                    ),
                    new OA\Property(
                        property: 'firstName',
                        type: 'string',
                        example: 'Jean'
                    ),
                    new OA\Property(
                        property: 'lastName',
                        type: 'string',
                        example: 'Dupont'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Utilisateur inscrit avec succès'
            ),
            new OA\Response(
                response: 400,
                description: 'Données invalides'
            ),
            new OA\Response(
                response: 409,
                description: 'Adresse email déjà utilisée'
            ),
        ]
    )]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(
                ['message' => 'JSON invalide'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $email = $data['email'] ?? null;
        $plainPassword = $data['password'] ?? null;

        if (
            !is_string($email)
            || trim($email) === ''
            || !is_string($plainPassword)
            || $plainPassword === ''
        ) {
            return $this->json(
                ['message' => 'Les champs email et password sont obligatoires'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $email = trim($email);

        $existingUser = $this->manager
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        if ($existingUser !== null) {
            return $this->json(
                ['message' => 'Cette adresse email est déjà utilisée'],
                Response::HTTP_CONFLICT
            );
        }

        $user = new User();

        $user->setEmail($email);
        $user->setPassword(
            $passwordHasher->hashPassword($user, $plainPassword)
        );

        // Une inscription publique crée TOUJOURS un client.
        $user->setRoles(['ROLE_USER']);

        $user->setCreatedAt(new DateTimeImmutable());

        if (isset($data['firstName']) && is_string($data['firstName'])) {
            $user->setfirstName(trim($data['firstName']));
        }

        if (isset($data['lastName']) && is_string($data['lastName'])) {
            $user->setlastName(trim($data['lastName']));
        }

        $this->manager->persist($user);
        $this->manager->flush();

        return $this->json(
            [
                'user' => $user->getUserIdentifier(),
                'apiToken' => $user->getApiToken(),
                'roles' => $user->getRoles(),
            ],
            Response::HTTP_CREATED
        );
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    #[OA\Post(
        path: '/api/login',
        summary: 'Connecter un utilisateur',
        requestBody: new OA\RequestBody(
            required: true,
            description: "Données de l'utilisateur pour se connecter",
            content: new OA\JsonContent(
                type: 'object',
                required: ['username', 'password'],
                properties: [
                    new OA\Property(
                        property: 'username',
                        type: 'string',
                        example: 'adresse@email.com'
                    ),
                    new OA\Property(
                        property: 'password',
                        type: 'string',
                        example: 'Mot de passe'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connexion réussie'
            )
        ]
    )]
    public function login(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(
                ['message' => 'Missing credentials'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        return $this->json([
            'user' => $user->getUserIdentifier(),
            'apiToken' => $user->getApiToken(),
            'roles' => $user->getRoles(),
        ]);
    }
}