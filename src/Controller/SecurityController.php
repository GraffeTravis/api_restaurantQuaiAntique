<?php

namespace App\Controller;

use OpenApi\Attributes as OA;
use App\Entity\User;
use App\Security\PasswordPolicy;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Cookie, JsonResponse, Request, Response};
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
                        minLength: 12,
                        maxLength: 72,
                        description: '12 caractères minimum et 72 octets maximum.',
                        example: 'Mot de passe'
                    ),
                    new OA\Property(
                        property: 'firstName',
                        type: 'string',
                        minLength: 1,
                        maxLength: 64,
                        example: 'Jean'
                    ),
                    new OA\Property(
                        property: 'lastName',
                        type: 'string',
                        minLength: 1,
                        maxLength: 64,
                        example: 'Dupont'
                    ),
                    new OA\Property(
                        property: 'guestNumber',
                        type: 'integer',
                        minimum: 1,
                        maximum: 32767,
                        example: 4
                    ),
                    new OA\Property(
                        property: 'allergy',
                        type: 'string',
                        maxLength: 255,
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
                    new OA\Property(property: 'apiToken', type: 'string', description: 'Absent lorsque X-AUTH-SESSION vaut cookie; le jeton est alors transmis uniquement dans un cookie HttpOnly.'),
                    new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time'),
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
            new OA\Response(response: 429, description: 'Trop de tentatives d’inscription', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 503, description: 'Stockage de limitation temporairement indisponible', content: new OA\JsonContent(ref: '#/components/schemas/ServiceUnavailable')),
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
        $firstName = is_string($data['firstName'] ?? null) ? trim($data['firstName']) : '';
        $lastName = is_string($data['lastName'] ?? null) ? trim($data['lastName']) : '';
        $guestNumber = $data['guestNumber'] ?? null;

        if (
            !is_string($email)
            || strlen(trim($email)) > 180
            || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)
            || !PasswordPolicy::isValid($plainPassword)
            || $firstName === ''
            || mb_strlen($firstName) > 64
            || $lastName === ''
            || mb_strlen($lastName) > 64
            || !is_int($guestNumber)
            || $guestNumber < 1 || $guestNumber > 32767
            || (isset($data['allergy']) && (!is_string($data['allergy']) || mb_strlen($data['allergy']) > 255))
        ) {
            return $this->json(
                ['message' => 'Email valide, nom et prénom de 1 à 64 caractères, convives entiers et mot de passe de 12 caractères minimum (72 octets maximum) requis.'],
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

        $token = $user->issueApiToken();
        $this->manager->persist($user);
        $this->manager->flush();

        return $this->sessionResponse($request, $user, $token, Response::HTTP_CREATED);
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
                    new OA\Property(property: 'rememberMe', type: 'boolean', default: false, description: 'Prolonge la validité du jeton à sept jours au lieu d’une heure.'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connexion réussie',
                content: new OA\JsonContent(type: 'object', properties: [
                    new OA\Property(property: 'user', type: 'string'),
                    new OA\Property(property: 'apiToken', type: 'string', description: 'Absent en mode cookie X-AUTH-SESSION: cookie.'),
                    new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'authMode', type: 'string', enum: ['cookie'], description: 'Présent uniquement lorsque la session est remise dans un cookie HttpOnly.'),
                ])
            ),
            new OA\Response(response: 401, description: 'Identifiants absents ou incorrects (réponse du pare-feu Symfony ou du contrôleur)'),
            new OA\Response(response: 429, description: 'Trop de tentatives de connexion', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 503, description: 'Stockage de limitation temporairement indisponible', content: new OA\JsonContent(ref: '#/components/schemas/ServiceUnavailable')),
        ]
    )]
    public function login(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(
                ['message' => 'Missing credentials'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $data = json_decode($request->getContent(), true);
        $token = $user->issueApiToken(($data['rememberMe'] ?? false) === true);
        $this->manager->flush();

        return $this->sessionResponse($request, $user, $token, Response::HTTP_OK);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    #[OA\Post(
        summary: 'Révoquer la session courante',
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Session révoquée'),
            new OA\Response(response: 401, description: 'Session absente ou expirée'),
        ]
    )]
    public function logout(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $user->revokeApiToken();
        $this->manager->flush();

        $response = $this->json(['message' => 'Session révoquée']);
        $response->headers->setCookie(Cookie::create('__Host-quai_session')->withExpires(1)->withPath('/')->withSecure(true)->withHttpOnly(true)->withSameSite(Cookie::SAMESITE_LAX));
        $response->headers->setCookie(Cookie::create('quai_session')->withExpires(1)->withPath('/')->withSecure(false)->withHttpOnly(true)->withSameSite(Cookie::SAMESITE_LAX));

        return $response;
    }

    private function sessionResponse(Request $request, User $user, string $token, int $status): JsonResponse
    {
        $cookieMode = $request->headers->get('X-AUTH-SESSION') === 'cookie';
        $payload = [
            'user' => $user->getUserIdentifier(),
            'expiresAt' => $user->getApiTokenExpiresAt()?->format(DATE_ATOM),
            'roles' => $user->getRoles(),
        ];

        if (!$cookieMode) {
            $payload['apiToken'] = $token;
            return $this->json($payload, $status);
        }

        $secure = $request->isSecure();
        $originHost = parse_url((string) $request->headers->get('Origin'), PHP_URL_HOST);
        $sameSite = is_string($originHost) && str_ends_with($originHost, '.vercel.app')
            ? Cookie::SAMESITE_NONE
            : Cookie::SAMESITE_LAX;
        $name = $secure ? '__Host-quai_session' : 'quai_session';
        $payload['authMode'] = 'cookie';
        $response = $this->json($payload, $status);
        $response->headers->setCookie(Cookie::create(
            $name,
            $token,
            $user->getApiTokenExpiresAt() ?? 0,
            '/',
            null,
            $secure,
            true,
            false,
            $sameSite,
        ));

        return $response;
    }
}
