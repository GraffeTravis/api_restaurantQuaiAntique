<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Menu;
use App\Entity\MenuCategory;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\MenuRepository;
use App\Repository\RestaurantRepository;
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

#[Route('/api/menus', name: 'app_api_menus_')]
#[OA\Tag(
    name: 'Menus',
    description: 'Gestion des menus du restaurant'
)]
class MenuController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private MenuRepository $menuRepository,
        private CategoryRepository $categoryRepository,
        private RestaurantRepository $restaurantRepository,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Lister les menus',
        description: 'Récupère tous les menus.',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des menus'
            ),
        ]
    )]
    public function list(): JsonResponse
    {
        $menus = $this->menuRepository->findBy([], ['title' => 'ASC']);

        return $this->json([
            'menus' => array_map(
                fn (Menu $menu) => $this->serializeMenu($menu),
                $menus
            ),
            'total' => count($menus),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[OA\Get(
        summary: 'Afficher un menu',
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
            new OA\Response(response: 200, description: 'Menu trouvé'),
            new OA\Response(response: 404, description: 'Menu introuvable'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $menu = $this->menuRepository->find($id);

        if (!$menu) {
            return $this->json(
                ['message' => 'Menu introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json($this->serializeMenu($menu));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Créer un menu',
        description: 'Créer un menu et lui associer des catégories. Administrateur uniquement.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['title', 'description', 'price', 'restaurantId'],
                properties: [
                    new OA\Property(
                        property: 'title',
                        type: 'string',
                        maxLength: 64,
                        example: 'Menu découverte'
                    ),
                    new OA\Property(
                        property: 'description',
                        type: 'string',
                        example: 'Entrée, plat et dessert au choix.'
                    ),
                    new OA\Property(
                        property: 'price',
                        type: 'integer',
                        minimum: 0,
                        example: 45
                    ),
                    new OA\Property(
                        property: 'restaurantId',
                        type: 'integer',
                        example: 1
                    ),
                    new OA\Property(
                        property: 'categoryIds',
                        type: 'array',
                        items: new OA\Items(type: 'integer'),
                        example: [1, 2, 3]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Menu créé'),
            new OA\Response(response: 400, description: 'Données invalides'),
            new OA\Response(response: 404, description: 'Restaurant ou catégorie introuvable'),
            new OA\Response(response: 401, description: 'Utilisateur non authentifié'),
            new OA\Response(response: 403, description: 'Accès interdit'),
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
        $description = trim((string) ($data['description'] ?? ''));
        $price = $data['price'] ?? null;
        $restaurantId = $data['restaurantId'] ?? null;
        $categoryIds = $data['categoryIds'] ?? [];

        if (
            $title === ''
            || $description === ''
            || $price === null
            || $restaurantId === null
        ) {
            return $this->json(
                [
                    'message' =>
                        'Le titre, la description, le prix et le restaurant sont obligatoires',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (mb_strlen($title) > 64) {
            return $this->json(
                ['message' => 'Le titre ne peut pas dépasser 64 caractères'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_numeric($price) || (int) $price < 0 || (int) $price > 32767) {
            return $this->json(
                ['message' => 'Le prix doit être un entier compris entre 0 et 32767'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_numeric($restaurantId)) {
            return $this->json(
                ['message' => 'restaurantId doit être un entier'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_array($categoryIds)) {
            return $this->json(
                ['message' => 'categoryIds doit être un tableau'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $restaurant = $this->restaurantRepository->find((int) $restaurantId);

        if (!$restaurant instanceof Restaurant) {
            return $this->json(
                ['message' => 'Restaurant introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        $menu = new Menu();

        $menu
            ->setTitle($title)
            ->setDescription($description)
            ->setPrice((int) $price)
            ->setRestaurant($restaurant)
            ->setCreatedAt(new DateTimeImmutable());

        $categoryError = $this->assignCategories($menu, $categoryIds);

        if ($categoryError) {
            return $categoryError;
        }

        $errors = $this->validator->validate($menu);

        if (count($errors) > 0) {
            return $this->json(
                [
                    'message' => 'Données invalides',
                    'errors' => $this->formatValidationErrors($errors),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->manager->persist($menu);
        $this->manager->flush();

        return $this->json(
            [
                'message' => 'Menu créé avec succès',
                'menu' => $this->serializeMenu($menu),
            ],
            Response::HTTP_CREATED
        );
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        summary: 'Modifier un menu',
        description: 'Modifier un menu et ses catégories. Administrateur uniquement.',
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
                required: ['title', 'description', 'price', 'restaurantId'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Menu découverte'),
                    new OA\Property(property: 'description', type: 'string', example: 'Nouvelle description.'),
                    new OA\Property(property: 'price', type: 'integer', example: 48),
                    new OA\Property(property: 'restaurantId', type: 'integer', example: 1),
                    new OA\Property(
                        property: 'categoryIds',
                        type: 'array',
                        items: new OA\Items(type: 'integer'),
                        example: [1, 2]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Menu modifié'),
            new OA\Response(response: 404, description: 'Menu, restaurant ou catégorie introuvable'),
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

        $menu = $this->menuRepository->find($id);

        if (!$menu) {
            return $this->json(
                ['message' => 'Menu introuvable'],
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
        $description = trim((string) ($data['description'] ?? ''));
        $price = $data['price'] ?? null;
        $restaurantId = $data['restaurantId'] ?? null;
        $categoryIds = $data['categoryIds'] ?? [];

        if (
            $title === ''
            || $description === ''
            || $price === null
            || $restaurantId === null
        ) {
            return $this->json(
                [
                    'message' =>
                        'Le titre, la description, le prix et le restaurant sont obligatoires',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (mb_strlen($title) > 64) {
            return $this->json(
                ['message' => 'Le titre ne peut pas dépasser 64 caractères'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_numeric($price) || (int) $price < 0 || (int) $price > 32767) {
            return $this->json(
                ['message' => 'Le prix doit être un entier compris entre 0 et 32767'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_numeric($restaurantId)) {
            return $this->json(
                ['message' => 'restaurantId doit être un entier'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_array($categoryIds)) {
            return $this->json(
                ['message' => 'categoryIds doit être un tableau'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $restaurant = $this->restaurantRepository->find((int) $restaurantId);

        if (!$restaurant instanceof Restaurant) {
            return $this->json(
                ['message' => 'Restaurant introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        $menu
            ->setTitle($title)
            ->setDescription($description)
            ->setPrice((int) $price)
            ->setRestaurant($restaurant)
            ->setUpdatedAt(new DateTimeImmutable());

        foreach ($menu->getMenuCategories()->toArray() as $menuCategory) {
            $menu->removeMenuCategory($menuCategory);
            $this->manager->remove($menuCategory);
        }

        $categoryError = $this->assignCategories($menu, $categoryIds);

        if ($categoryError) {
            return $categoryError;
        }

        $errors = $this->validator->validate($menu);

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
            'message' => 'Menu modifié avec succès',
            'menu' => $this->serializeMenu($menu),
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Supprimer un menu',
        description: 'Supprime un menu ainsi que ses associations avec les catégories.',
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
            new OA\Response(response: 204, description: 'Menu supprimé'),
            new OA\Response(response: 404, description: 'Menu introuvable'),
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

        $menu = $this->menuRepository->find($id);

        if (!$menu) {
            return $this->json(
                ['message' => 'Menu introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        foreach ($menu->getMenuCategories()->toArray() as $menuCategory) {
            $this->manager->remove($menuCategory);
        }

        $this->manager->remove($menu);
        $this->manager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function assignCategories(
        Menu $menu,
        array $categoryIds
    ): ?JsonResponse {
        $uniqueIds = array_unique(array_map('intval', $categoryIds));

        foreach ($uniqueIds as $categoryId) {
            $category = $this->categoryRepository->find($categoryId);

            if (!$category instanceof Category) {
                return $this->json(
                    [
                        'message' => 'Catégorie introuvable',
                        'categoryId' => $categoryId,
                    ],
                    Response::HTTP_NOT_FOUND
                );
            }

            $menuCategory = new MenuCategory();

            $menuCategory
                ->setMenu($menu)
                ->setCategory($category);

            $menu->addMenuCategory($menuCategory);

            $this->manager->persist($menuCategory);
        }

        return null;
    }

    private function serializeMenu(Menu $menu): array
    {
        $categories = [];

        foreach ($menu->getMenuCategories() as $menuCategory) {
            $category = $menuCategory->getCategory();

            if (!$category) {
                continue;
            }

            $categories[] = [
                'id' => $category->getId(),
                'title' => $category->getTitle(),
            ];
        }

        return [
            'id' => $menu->getId(),
            'title' => $menu->getTitle(),
            'description' => $menu->getDescription(),
            'price' => $menu->getPrice(),
            'restaurant' => [
                'id' => $menu->getRestaurant()?->getId(),
                'name' => $menu->getRestaurant()?->getName(),
            ],
            'categories' => $categories,
            'createdAt' => $menu->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt' => $menu->getUpdatedAt()?->format(DATE_ATOM),
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
