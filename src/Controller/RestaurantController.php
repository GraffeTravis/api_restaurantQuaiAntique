<?php

namespace App\Controller;

use OpenApi\Attributes as OA;
use App\Entity\Restaurant;
use App\Repository\RestaurantRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/restaurant', name: 'app_api_restaurant_')]
class RestaurantController extends AbstractController
{
    public function __construct(private EntityManagerInterface $manager, private RestaurantRepository $repository)
    {
    }
           
    #[Route(methods: 'POST')]
    #[OA\Post(
            path: '/api/restaurant',
            summary: 'Créer un restaurant',
            requestBody: new OA\RequestBody(
                required: true,
                description: "Données de l'utilisateur à inscrire",
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'Nom du restaurant'),
                        new OA\Property(property: 'description', type: 'string', example: 'Description du restaurant'),
                    ]
                )
            ),
            responses: [
                new OA\Response(
                    response: 200,
                    description: 'Restaurant créé avec succès',
                    content: new OA\JsonContent(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'name', type: 'string', example: 'Nom du restaurant'),
                            new OA\Property(property: 'description', type: 'string', example: 'Description du restaurant'),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        ]
                    )
                ),
                new OA\Response(
                    response: 404,
                    description: 'Restaurant non trouvé'
                )
            ]
        )]

    public function new(Request $request): JsonResponse

    {

        $restaurant = $this->serializer->deserialize($request->getContent(), Restaurant::class, 'json');

        $restaurant->setCreatedAt(new DateTimeImmutable());

        $this->manager->persist($restaurant);

        $this->manager->flush();

        $responseData = $this->serializer->serialize($restaurant, 'json');

        $location = $this->urlGenerator->generate(

            'app_api_restaurant_show',

            ['id' => $restaurant->getId()],

            UrlGeneratorInterface::ABSOLUTE_URL,

        );

        return new JsonResponse($responseData, Response::HTTP_CREATED, ["Location" => $location], true);

    //…

}
    

    #[Route('/{id}', name: 'show', methods: 'GET')]
    #[OA\Get(
            path: '/api/restaurant/{id}',
            summary: 'Afficher un restaurant par ID',
            parameters: [
                new OA\Parameter(
                    name: 'id',
                    in: 'path',
                    required: true,
                    description: 'ID du restaurant à afficher',
                    schema: new OA\Schema(type: 'integer')
                )
            ],
            responses: [
                new OA\Response(
                    response: 200,
                    description: 'Restaurant trouvé avec succès',
                    content: new OA\JsonContent(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'name', type: 'string', example: 'Nom du restaurant'),
                            new OA\Property(property: 'description', type: 'string', example: 'Description du restaurant'),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        ]
                    )
                ),
                new OA\Response(
                    response: 404,
                    description: 'Restaurant non trouvé'
                )
            ]
        )]
    public function show(int $id): Response
    {
        $restaurant = $this->repository->findOneBy(['id' => $id]);

        if (!$restaurant) {
            throw $this->createNotFoundException("No Restaurant found for {$id} id");
        }

        return $this->json(
            ['message' => "A Restaurant was found : {$restaurant->getName()} for {$restaurant->getId()} id"]
        );
    } 

    #[Route('/{id}', name: 'edit', methods: 'PUT')]
    public function edit(int $id): Response
    {
        $restaurant = $this->repository->findOneBy(['id' => $id]);

        if (!$restaurant) {
            throw $this->createNotFoundException("No Restaurant found for {$id} id");
        }

        $restaurant->setName('Restaurant name updated');
        $this->manager->flush();

        return $this->redirectToRoute('app_api_restaurant_show', ['id' => $restaurant->getId()]);
    }

    #[Route('/{id}', name: 'delete', methods: 'DELETE')]
    public function delete(int $id): Response
    {
        $restaurant = $this->repository->findOneBy(['id' => $id]);
        if (!$restaurant) {
            throw $this->createNotFoundException("No Restaurant found for {$id} id");
        }

        $this->manager->remove($restaurant);
        $this->manager->flush();

        return $this->json(['message' => "Restaurant resource deleted"], Response::HTTP_NO_CONTENT);
    }
// …
   
}
