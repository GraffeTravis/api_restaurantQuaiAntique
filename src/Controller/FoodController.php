<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Food;
use App\Entity\FoodCategory;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\FoodRepository;
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

#[Route('/api/foods', name: 'app_api_foods_')]
#[OA\Tag(
    name: 'Foods',
    description: 'Gestion des plats de la carte'
)]
class FoodController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private FoodRepository $foodRepository,
        private CategoryRepository $categoryRepository,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Lister les plats',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des plats'
            ),
        ]
    )]
    public function list(): JsonResponse
    {
        $foods = $this->foodRepository->findBy([], ['title' => 'ASC']);

        return $this->json([
            'foods' => array_map(
                fn (Food $food) => $this->serializeFood($food),
                $foods
            ),
            'total' => count($foods),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[OA\Get(
        summary: 'Afficher un plat',
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
            new OA\Response(response: 200, description: 'Plat trouvé'),
            new OA\Response(response: 404, description: 'Plat introuvable'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $food = $this->foodRepository->find($id);

        if (!$food) {
            return $this->json(
                ['message' => 'Plat introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json($this->serializeFood($food));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Créer un plat',
        description: 'Créer un plat et lui associer des catégories. Administrateur uniquement.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['title', 'description', 'price'],
                properties: [
                    new OA\Property(
                        property: 'title',
                        type: 'string',
                        maxLength: 64,
                        example: 'Tartare de truite'
                    ),
                    new OA\Property(
                        property: 'description',
                        type: 'string',
                        example: 'Truite de Savoie, herbes fraîches et condiments.'
                    ),
                    new OA\Property(
                        property: 'price',
                        type: 'integer',
                        minimum: 0,
                        example: 18
                    ),
                    new OA\Property(
                        property: 'categoryIds',
                        type: 'array',
                        items: new OA\Items(type: 'integer'),
                        example: [1]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Plat créé'),
            new OA\Response(response: 400, description: 'Données invalides'),
            new OA\Response(response: 404, description: 'Catégorie introuvable'),
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
        $categoryIds = $data['categoryIds'] ?? [];

        if ($title === '' || $description === '' || $price === null) {
            return $this->json(
                ['message' => 'Le titre, la description et le prix sont obligatoires'],
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

        if (!is_array($categoryIds)) {
            return $this->json(
                ['message' => 'categoryIds doit être un tableau'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $food = new Food();

        $food
            ->setTitle($title)
            ->setDescription($description)
            ->setPrice((int) $price)
            ->setCreatedAt(new DateTimeImmutable());

        $this->assignCategories($food, $categoryIds);

        $errors = $this->validator->validate($food);

        if (count($errors) > 0) {
            return $this->json(
                [
                    'message' => 'Données invalides',
                    'errors' => $this->formatValidationErrors($errors),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->manager->persist($food);
        $this->manager->flush();

        return $this->json(
            [
                'message' => 'Plat créé avec succès',
                'food' => $this->serializeFood($food),
            ],
            Response::HTTP_CREATED
        );
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        summary: 'Modifier un plat',
        description: 'Modifier un plat et ses catégories. Administrateur uniquement.',
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
                required: ['title', 'description', 'price'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Tartare de truite'),
                    new OA\Property(property: 'description', type: 'string', example: 'Nouvelle description.'),
                    new OA\Property(property: 'price', type: 'integer', example: 19),
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
            new OA\Response(response: 200, description: 'Plat modifié'),
            new OA\Response(response: 404, description: 'Plat ou catégorie introuvable'),
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

        $food = $this->foodRepository->find($id);

        if (!$food) {
            return $this->json(
                ['message' => 'Plat introuvable'],
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
        $categoryIds = $data['categoryIds'] ?? [];

        if ($title === '' || $description === '' || $price === null) {
            return $this->json(
                ['message' => 'Le titre, la description et le prix sont obligatoires'],
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

        if (!is_array($categoryIds)) {
            return $this->json(
                ['message' => 'categoryIds doit être un tableau'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $food
            ->setTitle($title)
            ->setDescription($description)
            ->setPrice((int) $price)
            ->setUpdatedAt(new DateTimeImmutable());

        $this->removeCategories($food);
        $this->assignCategories($food, $categoryIds);

        $errors = $this->validator->validate($food);

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
            'message' => 'Plat modifié avec succès',
            'food' => $this->serializeFood($food),
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Supprimer un plat',
        description: 'Supprime un plat ainsi que ses associations avec les catégories.',
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
            new OA\Response(response: 204, description: 'Plat supprimé'),
            new OA\Response(response: 404, description: 'Plat introuvable'),
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

        $food = $this->foodRepository->find($id);

        if (!$food) {
            return $this->json(
                ['message' => 'Plat introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        foreach ($food->getFoodCategories()->toArray() as $foodCategory) {
            $this->manager->remove($foodCategory);
        }

        $this->manager->remove($food);
        $this->manager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function assignCategories(Food $food, array $categoryIds): void
    {
        $uniqueIds = array_unique(array_map('intval', $categoryIds));

        foreach ($uniqueIds as $categoryId) {
            $category = $this->categoryRepository->find($categoryId);

            if (!$category instanceof Category) {
                throw $this->createNotFoundException(
                    sprintf('Catégorie %d introuvable', $categoryId)
                );
            }

            $foodCategory = new FoodCategory();
            $foodCategory
                ->setFood($food)
                ->setCategory($category);

            $food->addFoodCategory($foodCategory);

            $this->manager->persist($foodCategory);
        }
    }

    private function removeCategories(Food $food): void
    {
        foreach ($food->getFoodCategories()->toArray() as $foodCategory) {
            $food->removeFoodCategory($foodCategory);
            $this->manager->remove($foodCategory);
        }
    }

    private function serializeFood(Food $food): array
    {
        $categories = [];

        foreach ($food->getFoodCategories() as $foodCategory) {
            $category = $foodCategory->getCategory();

            if (!$category) {
                continue;
            }

            $categories[] = [
                'id' => $category->getId(),
                'title' => $category->getTitle(),
            ];
        }

        return [
            'id' => $food->getId(),
            'title' => $food->getTitle(),
            'description' => $food->getDescription(),
            'price' => $food->getPrice(),
            'categories' => $categories,
            'createdAt' => $food->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt' => $food->getUpdatedAt()?->format(DATE_ATOM),
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
