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
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            description: "Données de l'utilisateur à inscrire",
            content: new OA\JsonContent(
                type: 'object',
                required: ['email', 'password', 'firstName', 'lastName', 'guestNumber'],
                properties: [
                    new OA\Property(
                        property: 'email',
                        type: 'string',
                        example: 'adresse@email.com'
                    ),
                    new OA\Property(
                        property: 'password',
                        type: 'string',
                        format: 'password',
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
                    new OA\Property(
                        property: 'guestNumber',
                        type: 'integer',
                        example: 4
                    ),
                    new OA\Property(
                        property: 'allergy',
                        type: 'string',
                        example: 'Arachides'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Utilisateur inscrit avec succès',
                content: new OA\JsonContent(type: 'object', properties: [
                    new OA\Property(property: 'user', type: 'string'),
                    new OA\Property(property: 'apiToken', type: 'string'),
                    new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
                ])
            ),
            new OA\Response(
                response: 400,
                description: 'JSON ou champs invalides',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 409,
                description: 'Adresse email déjà utilisée',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
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
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));
        $guestNumber = $data['guestNumber'] ?? null;

        if (
            !is_string($email)
            || trim($email) === ''
            || !is_string($plainPassword)
            || $plainPassword === ''
            || $firstName === ''
            || $lastName === ''
            || !is_numeric($guestNumber)
            || (int) $guestNumber < 1
        ) {
            return $this->json(
                ['message' => 'Les champs email, password, firstName, lastName et guestNumber sont obligatoires'],
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

        $user->setfirstName($firstName);
        $user->setlastName($lastName);
        $user->setGuestNumber((int) $guestNumber);

        if (isset($data['allergy']) && is_string($data['allergy'])) {
            $user->setAllergy(trim($data['allergy']));
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
        security: [],
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
                description: 'Connexion réussie',
                content: new OA\JsonContent(type: 'object', properties: [
                    new OA\Property(property: 'user', type: 'string'),
                    new OA\Property(property: 'apiToken', type: 'string'),
                    new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
                ])
            ),
            new OA\Response(response: 401, description: 'Identifiants absents ou incorrects (réponse du pare-feu Symfony ou du contrôleur)'),
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
