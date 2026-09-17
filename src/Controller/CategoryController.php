<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\FoodCategory;
use App\Entity\MenuCategory;
use App\Entity\User;
use App\Repository\CategoryRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/categories', name: 'app_api_categories_')]
#[OA\Tag(
    name: 'Categories',
    description: 'Gestion des catégories de la carte'
)]
class CategoryController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private CategoryRepository $categoryRepository,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Lister les catégories',
        description: 'Récupère toutes les catégories de la carte.',
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des catégories',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'categories',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Category')
                        ),
                        new OA\Property(property: 'total', type: 'integer', example: 5),
                    ]
                )
            ),
        ]
    )]
    public function list(): JsonResponse
    {
        $categories = $this->categoryRepository->findBy([], ['title' => 'ASC']);

        return $this->json([
            'categories' => array_map(
                fn (Category $category) => $this->serializeCategory($category),
                $categories
            ),
            'total' => count($categories),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[OA\Get(
        summary: 'Afficher une catégorie',
        security: [],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                example: 1
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Catégorie trouvée',
                content: new OA\JsonContent(ref: '#/components/schemas/Category')
            ),
            new OA\Response(
                response: 404,
                description: 'Catégorie introuvable',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $category = $this->categoryRepository->find($id);

        if (!$category) {
            return $this->json(
                ['message' => 'Catégorie introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json($this->serializeCategory($category));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Créer une catégorie',
        description: 'Créer une nouvelle catégorie. Administrateur uniquement.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['title'],
                properties: [
                    new OA\Property(
                        property: 'title',
                        type: 'string',
                        maxLength: 64,
                        example: 'Entrées'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Catégorie créée', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'category', ref: '#/components/schemas/Category'),
            ])),
            new OA\Response(response: 400, description: 'JSON ou titre invalide', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'Utilisateur non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'Accès interdit', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'Validation de la catégorie échouée (message et errors)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'errors', type: 'object'),
            ])),
        ]
    )]
    public function create(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        $authError = $this->checkAdmin($user);

        if ($authError) {
            return $authError;
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(
                ['message' => 'JSON invalide'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            return $this->json(
                ['message' => 'Le titre est obligatoire'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (mb_strlen($title) > 64) {
            return $this->json(
                ['message' => 'Le titre ne peut pas dépasser 64 caractères'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $category = new Category();
        $category
            ->setTitle($title)
            ->setCreatedAt(new DateTimeImmutable());

        $errors = $this->validator->validate($category);

        if (count($errors) > 0) {
            return $this->json(
                [
                    'message' => 'Données invalides',
                    'errors' => $this->formatValidationErrors($errors),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->manager->persist($category);
        $this->manager->flush();

        return $this->json(
            [
                'message' => 'Catégorie créée avec succès',
                'category' => $this->serializeCategory($category),
            ],
            Response::HTTP_CREATED
        );
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        summary: 'Modifier une catégorie',
        description: 'Modifier une catégorie. Administrateur uniquement.',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                example: 1
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['title'],
                properties: [
                    new OA\Property(
                        property: 'title',
                        type: 'string',
                        maxLength: 64,
                        example: 'Entrées'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Catégorie modifiée', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'category', ref: '#/components/schemas/Category'),
            ])),
            new OA\Response(response: 400, description: 'JSON ou titre invalide', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'Utilisateur non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'Accès interdit', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'Catégorie introuvable', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'Validation de la catégorie échouée (message et errors)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'errors', type: 'object'),
            ])),
        ]
    )]
    public function update(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        $authError = $this->checkAdmin($user);

        if ($authError) {
            return $authError;
        }

        $category = $this->categoryRepository->find($id);

        if (!$category) {
            return $this->json(
                ['message' => 'Catégorie introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(
                ['message' => 'JSON invalide'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            return $this->json(
                ['message' => 'Le titre est obligatoire'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (mb_strlen($title) > 64) {
            return $this->json(
                ['message' => 'Le titre ne peut pas dépasser 64 caractères'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $category
            ->setTitle($title)
            ->setUpdatedAt(new DateTimeImmutable());

        $errors = $this->validator->validate($category);

        if (count($errors) > 0) {
            return $this->json(
                [
                    'message' => 'Données invalides',
                    'errors' => $this->formatValidationErrors($errors),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->manager->flush();

        return $this->json([
            'message' => 'Catégorie modifiée avec succès',
            'category' => $this->serializeCategory($category),
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Supprimer une catégorie',
        description: 'Supprime une catégorie ainsi que ses associations avec les menus et les plats.',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                example: 1
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Catégorie supprimée (sans corps)'),
            new OA\Response(response: 401, description: 'Utilisateur non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'Accès interdit', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'Catégorie introuvable', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ]
    )]
    public function delete(
        int $id,
        #[CurrentUser] ?User $user
    ): Response {
        $authError = $this->checkAdmin($user);

        if ($authError) {
            return $authError;
        }

        $category = $this->categoryRepository->find($id);

        if (!$category) {
            return $this->json(
                ['message' => 'Catégorie introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        foreach ($category->getFoodCategories()->toArray() as $foodCategory) {
            $this->manager->remove($foodCategory);
        }

        foreach ($category->getMenuCategories()->toArray() as $menuCategory) {
            $this->manager->remove($menuCategory);
        }

        $this->manager->remove($category);
        $this->manager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function serializeCategory(Category $category): array
    {
        return [
            'id' => $category->getId(),
            'title' => $category->getTitle(),
            'createdAt' => $category->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt' => $category->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }

    private function checkAdmin(?User $user): ?JsonResponse
    {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json(
                ['message' => 'Accès interdit'],
                Response::HTTP_FORBIDDEN
            );
        }

        return null;
    }

    private function formatValidationErrors(
        \Symfony\Component\Validator\ConstraintViolationListInterface $errors
    ): array {
        $formatted = [];

        foreach ($errors as $error) {
            $formatted[$error->getPropertyPath()][] = $error->getMessage();
        }

        return $formatted;
    }
}
