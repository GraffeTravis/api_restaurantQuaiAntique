<?php

namespace App\Controller;

use OpenApi\Attributes as OA;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\RestaurantRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/restaurant', name: 'app_api_restaurant_')]
#[OA\Tag(name: 'Restaurant', description: 'Gestion des restaurants')]
class RestaurantController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private RestaurantRepository $repository,
        private SerializerInterface $serializer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Créer un restaurant.
     * Seuls les administrateurs peuvent créer des restaurants.
     */
    #[Route(methods: 'POST')]
    #[OA\Post(
        path: '/api/restaurant',
        summary: 'Créer un restaurant',
        requestBody: new OA\RequestBody(
            required: true,
            description: "Données du restaurant à créer",
            content: new OA\JsonContent(
                type: 'object',
                required: ['name', 'description', 'maxGuest'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Quai Antique', maxLength: 32),
                    new OA\Property(property: 'description', type: 'string', example: 'Restaurant gastronomique'),
                    new OA\Property(property: 'maxGuest', type: 'integer', example: 50),
                    new OA\Property(property: 'amOpeningTime', type: 'array', items: new OA\Items(type: 'string'), example: ['11:30', '14:00']),
                    new OA\Property(property: 'pmOpeningTime', type: 'array', items: new OA\Items(type: 'string'), example: ['19:00', '23:00']),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Restaurant créé avec succès',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'name', type: 'string', example: 'Quai Antique'),
                        new OA\Property(property: 'description', type: 'string', example: 'Restaurant gastronomique'),
                        new OA\Property(property: 'maxGuest', type: 'integer', example: 50),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Données invalides',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Les champs name, description et maxGuest sont obligatoires'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Utilisateur non authentifié',
            ),
            new OA\Response(
                response: 403,
                description: 'Accès interdit (administrateur requis)',
            ),
        ]
    )]
    public function create(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        // Vérifier que l'utilisateur est administrateur
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(
                ['message' => 'Accès interdit'],
                Response::HTTP_FORBIDDEN
            );
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(
                ['message' => 'JSON invalide'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validation des champs obligatoires
        $requiredFields = ['name', 'description', 'maxGuest'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return $this->json(
                    ['message' => "Le champ '{$field}' est obligatoire"],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        // Validation du nom
        $name = trim($data['name'] ?? '');
        if (empty($name) || strlen($name) > 32) {
            return $this->json(
                ['message' => 'Le nom doit être non vide et < 32 caractères'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validation de la description
        $description = trim($data['description'] ?? '');
        if (empty($description)) {
            return $this->json(
                ['message' => 'La description ne peut pas être vide'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validation du nombre maximal de couverts
        $maxGuest = (int) $data['maxGuest'];
        if ($maxGuest < 1) {
            return $this->json(
                ['message' => 'maxGuest doit être supérieur à 0'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Créer le restaurant
        $restaurant = new Restaurant();
        $restaurant->setName($name);
        $restaurant->setDescription($description);
        $restaurant->setMaxGuest($maxGuest);
        $restaurant->setOwner($user);
        $restaurant->setCreatedAt(new DateTimeImmutable());

        // Horaires d'ouverture (optionnels)
        if (isset($data['amOpeningTime']) && is_array($data['amOpeningTime'])) {
            $restaurant->setAmOpeningTime($data['amOpeningTime']);
        }
        if (isset($data['pmOpeningTime']) && is_array($data['pmOpeningTime'])) {
            $restaurant->setPmOpeningTime($data['pmOpeningTime']);
        }

        $this->manager->persist($restaurant);
        $this->manager->flush();

        $location = $this->urlGenerator->generate(
            'app_api_restaurant_show',
            ['id' => $restaurant->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->json(
            [
                'id' => $restaurant->getId(),
                'name' => $restaurant->getName(),
                'description' => $restaurant->getDescription(),
                'maxGuest' => $restaurant->getMaxGuest(),
                'createdAt' => $restaurant->getCreatedAt()->format('c'),
            ],
            Response::HTTP_CREATED,
            ['Location' => $location]
        );
    }

    /**
     * Récupérer les détails d'un restaurant.
     */
    #[Route('/{id}', name: 'show', methods: 'GET')]
    #[OA\Get(
        path: '/api/restaurant/{id}',
        summary: 'Afficher un restaurant par ID',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID du restaurant',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Restaurant trouvé',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'name', type: 'string', example: 'Quai Antique'),
                        new OA\Property(property: 'description', type: 'string', example: 'Restaurant gastronomique'),
                        new OA\Property(property: 'maxGuest', type: 'integer', example: 50),
                        new OA\Property(property: 'amOpeningTime', type: 'array', items: new OA\Items(type: 'string'), example: ['11:30', '14:00']),
                        new OA\Property(property: 'pmOpeningTime', type: 'array', items: new OA\Items(type: 'string'), example: ['19:00', '23:00']),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Restaurant non trouvé',
            ),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $restaurant = $this->repository->find($id);

        if (!$restaurant) {
            return $this->json(
                ['message' => 'Restaurant introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json([
            'id' => $restaurant->getId(),
            'name' => $restaurant->getName(),
            'description' => $restaurant->getDescription(),
            'maxGuest' => $restaurant->getMaxGuest(),
            'amOpeningTime' => $restaurant->getAmOpeningTime(),
            'pmOpeningTime' => $restaurant->getPmOpeningTime(),
            'createdAt' => $restaurant->getCreatedAt()->format('c'),
        ]);
    }

    /**
     * Modifier un restaurant.
     * Seul le propriétaire peut modifier son restaurant.
     */
    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[OA\Put(
        path: '/api/restaurant/{id}',
        summary: 'Modifier un restaurant',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID du restaurant',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: "Données du restaurant à modifier",
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Quai Antique'),
                    new OA\Property(property: 'description', type: 'string', example: 'Restaurant gastronomique'),
                    new OA\Property(property: 'maxGuest', type: 'integer', example: 50),
                    new OA\Property(property: 'amOpeningTime', type: 'array', items: new OA\Items(type: 'string'), example: ['11:30', '14:00']),
                    new OA\Property(property: 'pmOpeningTime', type: 'array', items: new OA\Items(type: 'string'), example: ['19:00', '23:00']),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Restaurant modifié avec succès',
            ),
            new OA\Response(
                response: 400,
                description: 'Données invalides',
            ),
            new OA\Response(
                response: 401,
                description: 'Utilisateur non authentifié',
            ),
            new OA\Response(
                response: 403,
                description: 'Accès interdit (propriétaire requis)',
            ),
            new OA\Response(
                response: 404,
                description: 'Restaurant non trouvé',
            ),
        ]
    )]
    public function update(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $restaurant = $this->repository->find($id);

        if (!$restaurant) {
            return $this->json(
                ['message' => 'Restaurant introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Vérifier que l'utilisateur est le propriétaire du restaurant
        if ($restaurant->getOwner() !== $user) {
            return $this->json(
                ['message' => 'Accès interdit'],
                Response::HTTP_FORBIDDEN
            );
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(
                ['message' => 'JSON invalide'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Mettre à jour les champs fournis
        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (empty($name) || strlen($name) > 32) {
                return $this->json(
                    ['message' => 'Le nom doit être non vide et < 32 caractères'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $restaurant->setName($name);
        }

        if (isset($data['description'])) {
            $description = trim($data['description']);
            if (empty($description)) {
                return $this->json(
                    ['message' => 'La description ne peut pas être vide'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $restaurant->setDescription($description);
        }

        if (isset($data['maxGuest'])) {
            $maxGuest = (int) $data['maxGuest'];
            if ($maxGuest < 1) {
                return $this->json(
                    ['message' => 'maxGuest doit être supérieur à 0'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $restaurant->setMaxGuest($maxGuest);
        }

        if (isset($data['amOpeningTime']) && is_array($data['amOpeningTime'])) {
            $restaurant->setAmOpeningTime($data['amOpeningTime']);
        }

        if (isset($data['pmOpeningTime']) && is_array($data['pmOpeningTime'])) {
            $restaurant->setPmOpeningTime($data['pmOpeningTime']);
        }

        $restaurant->setUpdateAt(new DateTimeImmutable());

        $this->manager->flush();

        return $this->json([
            'id' => $restaurant->getId(),
            'name' => $restaurant->getName(),
            'description' => $restaurant->getDescription(),
            'maxGuest' => $restaurant->getMaxGuest(),
            'amOpeningTime' => $restaurant->getAmOpeningTime(),
            'pmOpeningTime' => $restaurant->getPmOpeningTime(),
            'createdAt' => $restaurant->getCreatedAt()->format('c'),
            'updatedAt' => $restaurant->getUpdateAt() ? $restaurant->getUpdateAt()->format('c') : null,
        ]);
    }

    /**
     * Supprimer un restaurant.
     * Seul le propriétaire ou un administrateur peut supprimer un restaurant.
     */
    #[Route('/{id}', name: 'delete', methods: 'DELETE')]
    #[OA\Delete(
        path: '/api/restaurant/{id}',
        summary: 'Supprimer un restaurant',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID du restaurant',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 204,
                description: 'Restaurant supprimé avec succès',
            ),
            new OA\Response(
                response: 401,
                description: 'Utilisateur non authentifié',
            ),
            new OA\Response(
                response: 403,
                description: 'Accès interdit',
            ),
            new OA\Response(
                response: 404,
                description: 'Restaurant non trouvé',
            ),
        ]
    )]
    public function delete(
        int $id,
        #[CurrentUser] ?User $user
    ): Response {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $restaurant = $this->repository->find($id);

        if (!$restaurant) {
            return $this->json(
                ['message' => 'Restaurant introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Vérifier que l'utilisateur est le propriétaire ou un admin
        $isOwner = $restaurant->getOwner() === $user;
        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles());

        if (!$isOwner && !$isAdmin) {
            return $this->json(
                ['message' => 'Accès interdit'],
                Response::HTTP_FORBIDDEN
            );
        }

        $this->manager->remove($restaurant);
        $this->manager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
