<?php

namespace App\Controller;

use OpenApi\Attributes as OA;
use App\Entity\Picture;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\PictureRepository;
use App\Repository\RestaurantRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/api/restaurants/{restaurantId}/pictures', name: 'app_api_pictures_')]
#[OA\Tag(name: 'Gallery', description: 'Gestion de la galerie photos des restaurants')]
class PictureController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private PictureRepository $pictureRepository,
        private RestaurantRepository $restaurantRepository,
        private SluggerInterface $slugger,
    ) {
    }

    /**
     * Ajouter une image à la galerie.
     * Seul l'admin peut ajouter des images.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/restaurants/{restaurantId}/pictures',
        summary: 'Ajouter une image à la galerie',
        description: 'Ajouter une nouvelle image à la galerie du restaurant (seul l\'administrateur)',
        parameters: [
            new OA\Parameter(
                name: 'restaurantId',
                in: 'path',
                required: true,
                description: 'ID du restaurant',
                schema: new OA\Schema(type: 'integer'),
                example: 1
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Image et titre (form-data)',
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['title', 'imageFile'],
                    properties: [
                        new OA\Property(
                            property: 'title',
                            type: 'string',
                            description: 'Titre de l\'image',
                            example: 'Salle principale'
                        ),
                        new OA\Property(
                            property: 'imageFile',
                            type: 'string',
                            format: 'binary',
                            description: 'Fichier image (JPG, PNG, WebP - max 5MB)'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Image ajoutée avec succès',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Image ajoutée avec succès'),
                        new OA\Property(
                            property: 'picture',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'title', type: 'string', example: 'Salle principale'),
                                new OA\Property(property: 'slug', type: 'string', example: 'salle-principale'),
                                new OA\Property(property: 'imageUrl', type: 'string', example: '/uploads/restaurants/salle-principale-507d.jpg'),
                                new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Données invalides ou image manquante',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Le titre et l\'image sont obligatoires'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Utilisateur non authentifié',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Utilisateur non authentifié'),
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Accès interdit (administrateur requis)',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Accès interdit'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Restaurant non trouvé',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Restaurant introuvable'),
                    ]
                )
            ),
        ]
    )]
    public function create(
        int $restaurantId,
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

        $restaurant = $this->restaurantRepository->find($restaurantId);

        if (!$restaurant) {
            return $this->json(
                ['message' => 'Restaurant introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Récupérer le titre et l'image
        $title = $request->request->get('title');
        $imageFile = $request->files->get('imageFile');

        if (!$title || !$imageFile) {
            return $this->json(
                ['message' => 'Le titre et l\'image sont obligatoires'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validation du titre
        $title = trim($title);
        if (empty($title) || strlen($title) > 255) {
            return $this->json(
                ['message' => 'Le titre doit être non vide et maximum 255 caractères'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validation du type d'image
        $mimeType = $imageFile->getMimeType();
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mimeType, $allowedMimes)) {
            return $this->json(
                ['message' => 'Format d\'image invalide. Acceptés: JPG, PNG, WebP'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validation de la taille (max 5MB)
        if ($imageFile->getSize() > 5 * 1024 * 1024) {
            return $this->json(
                ['message' => 'L\'image ne doit pas dépasser 5MB'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Créer le slug
        $slug = strtolower($this->slugger->slug($title));

        // Créer la picture
        $picture = new Picture();
        $picture->setTitle($title);
        $picture->setSlug($slug);
        $picture->setImageFile($imageFile);
        $picture->setRestaurant($restaurant);
        $picture->setCreatedAt(new DateTimeImmutable());

        $this->manager->persist($picture);
        $this->manager->flush();

        return $this->json(
            [
                'message' => 'Image ajoutée avec succès',
                'picture' => [
                    'id' => $picture->getId(),
                    'title' => $picture->getTitle(),
                    'slug' => $picture->getSlug(),
                    'imageUrl' => $picture->getImageUrl(),
                    'createdAt' => $picture->getCreatedAt()->format('c'),
                ]
            ],
            Response::HTTP_CREATED
        );
    }

    /**
     * Lister les images de la galerie.
     * Endpoint public - accessible à tous.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/restaurants/{restaurantId}/pictures',
        summary: 'Lister la galerie du restaurant',
        description: 'Récupère toutes les images de la galerie du restaurant (public)',
        parameters: [
            new OA\Parameter(
                name: 'restaurantId',
                in: 'path',
                required: true,
                description: 'ID du restaurant',
                schema: new OA\Schema(type: 'integer'),
                example: 1
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des images',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'pictures',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'title', type: 'string', example: 'Salle principale'),
                                    new OA\Property(property: 'slug', type: 'string', example: 'salle-principale'),
                                    new OA\Property(property: 'imageUrl', type: 'string', example: '/uploads/restaurants/salle-principale-507d.jpg'),
                                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                                ]
                            )
                        ),
                        new OA\Property(property: 'total', type: 'integer', example: 5),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Restaurant non trouvé',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Restaurant introuvable'),
                    ]
                )
            ),
        ]
    )]
    public function list(int $restaurantId): JsonResponse
    {
        $restaurant = $this->restaurantRepository->find($restaurantId);

        if (!$restaurant) {
            return $this->json(
                ['message' => 'Restaurant introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        $pictures = $this->pictureRepository->findBy(['restaurant' => $restaurant], ['createdAt' => 'DESC']);

        return $this->json([
            'pictures' => array_map(
                fn($picture) => [
                    'id' => $picture->getId(),
                    'title' => $picture->getTitle(),
                    'slug' => $picture->getSlug(),
                    'imageUrl' => $picture->getImageUrl(),
                    'createdAt' => $picture->getCreatedAt()->format('c'),
                ],
                $pictures
            ),
            'total' => count($pictures),
        ]);
    }

    /**
     * Voir une image de la galerie.
     * Endpoint public - accessible à tous.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[OA\Get(
        path: '/api/restaurants/{restaurantId}/pictures/{id}',
        summary: 'Voir une image (public)',
        parameters: [
            new OA\Parameter(
                name: 'restaurantId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                example: 1
            ),
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                example: 42
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Détails de l\'image',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'title', type: 'string', example: 'Salle principale'),
                        new OA\Property(property: 'slug', type: 'string', example: 'salle-principale'),
                        new OA\Property(property: 'imageUrl', type: 'string', example: '/uploads/restaurants/salle-principale-507d.jpg'),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Image ou restaurant non trouvé',
            ),
        ]
    )]
    public function show(int $restaurantId, int $id): JsonResponse
    {
        $picture = $this->pictureRepository->find($id);

        if (!$picture || $picture->getRestaurant()->getId() !== $restaurantId) {
            return $this->json(
                ['message' => 'Image introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json([
            'id' => $picture->getId(),
            'title' => $picture->getTitle(),
            'slug' => $picture->getSlug(),
            'imageUrl' => $picture->getImageUrl(),
            'createdAt' => $picture->getCreatedAt()->format('c'),
        ]);
    }

    /**
     * Modifier le titre d'une image.
     * Seul l'admin peut modifier.
     */
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/restaurants/{restaurantId}/pictures/{id}',
        summary: 'Modifier une image',
        description: 'Modifier le titre d\'une image (seul l\'administrateur)',
        parameters: [
            new OA\Parameter(
                name: 'restaurantId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                example: 1
            ),
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                example: 42
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Nouveau titre',
            content: new OA\JsonContent(
                type: 'object',
                required: ['title'],
                properties: [
                    new OA\Property(
                        property: 'title',
                        type: 'string',
                        example: 'Salle à manger rénovée'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Image modifiée avec succès',
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
                description: 'Accès interdit (administrateur requis)',
            ),
            new OA\Response(
                response: 404,
                description: 'Image ou restaurant non trouvé',
            ),
        ]
    )]
    public function update(
        int $restaurantId,
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

        // Vérifier que l'utilisateur est administrateur
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(
                ['message' => 'Accès interdit'],
                Response::HTTP_FORBIDDEN
            );
        }

        $picture = $this->pictureRepository->find($id);

        if (!$picture || $picture->getRestaurant()->getId() !== $restaurantId) {
            return $this->json(
                ['message' => 'Image introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['title'])) {
            return $this->json(
                ['message' => 'Le titre est obligatoire'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $title = trim($data['title']);
        if (empty($title) || strlen($title) > 255) {
            return $this->json(
                ['message' => 'Le titre doit être non vide et maximum 255 caractères'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $picture->setTitle($title);
        $picture->setSlug(strtolower($this->slugger->slug($title)));
        $picture->setUpdatedAt(new DateTimeImmutable());
        $this->manager->flush();

        return $this->json([
            'message' => 'Image modifiée avec succès',
            'picture' => [
                'id' => $picture->getId(),
                'title' => $picture->getTitle(),
                'slug' => $picture->getSlug(),
                'imageUrl' => $picture->getImageUrl(),
                'updatedAt' => $picture->getUpdatedAt()?->format('c'),
            ]
        ]);
    }

    /**
     * Supprimer une image.
     * Seul l'admin peut supprimer.
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/restaurants/{restaurantId}/pictures/{id}',
        summary: 'Supprimer une image',
        description: 'Supprimer une image de la galerie (seul l\'administrateur)',
        parameters: [
            new OA\Parameter(
                name: 'restaurantId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                example: 1
            ),
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                example: 42
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Image supprimée avec succès',
            ),
            new OA\Response(
                response: 401,
                description: 'Utilisateur non authentifié',
            ),
            new OA\Response(
                response: 403,
                description: 'Accès interdit (administrateur requis)',
            ),
            new OA\Response(
                response: 404,
                description: 'Image ou restaurant non trouvé',
            ),
        ]
    )]
    public function delete(
        int $restaurantId,
        int $id,
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

        $picture = $this->pictureRepository->find($id);

        if (!$picture || $picture->getRestaurant()->getId() !== $restaurantId) {
            return $this->json(
                ['message' => 'Image introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        $this->manager->remove($picture);
        $this->manager->flush();

        return $this->json(
            ['message' => 'Image supprimée avec succès']
        );
    }
}