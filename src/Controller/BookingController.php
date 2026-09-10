<?php

namespace App\Controller;

use OpenApi\Attributes as OA;
use App\Entity\Booking;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\RestaurantRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/bookings', name: 'app_api_bookings_')]
#[OA\Tag(name: 'Bookings', description: 'Gestion des réservations au restaurant')]
class BookingController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private BookingRepository $bookingRepository,
        private RestaurantRepository $restaurantRepository,
    ) {
    }

    /**
     * Créer une réservation.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/bookings',
        summary: 'Créer une nouvelle réservation',
        description: 'Créer une réservation pour un restaurant à une date et heure spécifiées',
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Données de la réservation',
            content: new OA\JsonContent(
                type: 'object',
                required: ['restaurantId', 'date', 'hour', 'guestNumber'],
                properties: [
                    new OA\Property(
                        property: 'restaurantId',
                        type: 'integer',
                        description: 'ID du restaurant',
                        example: 1
                    ),
                    new OA\Property(
                        property: 'date',
                        type: 'string',
                        format: 'date',
                        description: 'Date de la réservation (format YYYY-MM-DD)',
                        example: '2024-12-25'
                    ),
                    new OA\Property(
                        property: 'hour',
                        type: 'string',
                        description: 'Heure de la réservation (format HH:MM, par tranche de 15min)',
                        example: '19:30'
                    ),
                    new OA\Property(
                        property: 'guestNumber',
                        type: 'integer',
                        description: 'Nombre de couverts',
                        example: 4
                    ),
                    new OA\Property(
                        property: 'allergy',
                        type: 'string',
                        description: 'Allergies ou restrictions alimentaires (optionnel)',
                        example: 'Sans gluten, pas de cacahuètes',
                        nullable: true
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Réservation créée avec succès',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Réservation créée avec succès'
                        ),
                        new OA\Property(
                            property: 'booking',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 42),
                                new OA\Property(property: 'date', type: 'string', example: '2024-12-25'),
                                new OA\Property(property: 'hour', type: 'string', example: '19:30'),
                                new OA\Property(property: 'guestNumber', type: 'integer', example: 4),
                                new OA\Property(property: 'allergy', type: 'string', example: 'Pas de gluten'),
                                new OA\Property(property: 'restaurantId', type: 'integer', example: 1),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Données invalides ou manquantes',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Les champs restaurantId, date, hour et guestNumber sont obligatoires'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Utilisateur non authentifié',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Utilisateur non authentifié'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Restaurant non trouvé',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Restaurant introuvable'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 409,
                description: 'Capacité du restaurant dépassée',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Le restaurant est complet pour ce créneau'),
                        new OA\Property(property: 'capacity', type: 'integer', example: 50),
                        new OA\Property(property: 'alreadyReserved', type: 'integer', example: 48),
                        new OA\Property(property: 'requested', type: 'integer', example: 4),
                    ]
                )
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

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(
                ['message' => 'JSON invalide'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Vérification des champs obligatoires
        if (
            !isset($data['restaurantId']) ||
            !isset($data['date']) ||
            !isset($data['hour']) ||
            !isset($data['guestNumber'])
        ) {
            return $this->json(
                ['message' => 'Les champs restaurantId, date, hour et guestNumber sont obligatoires'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $restaurant = $this->restaurantRepository->find($data['restaurantId']);

        if (!$restaurant) {
            return $this->json(
                ['message' => 'Restaurant introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        $guestNumber = (int) $data['guestNumber'];

        if ($guestNumber < 1) {
            return $this->json(
                ['message' => 'Le nombre de couverts doit être supérieur à 0'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($guestNumber > $restaurant->getMaxGuest()) {
            return $this->json(
                ['message' => 'Le nombre de couverts demandé dépasse la capacité maximale du restaurant'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validation de la date
        $orderDate = DateTime::createFromFormat('Y-m-d', $data['date']);

        if (!$orderDate || $orderDate->format('Y-m-d') !== $data['date']) {
            return $this->json(
                ['message' => 'Format de date invalide. Utilisez YYYY-MM-DD'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validation de l'heure
        $orderHour = DateTime::createFromFormat('H:i', $data['hour']);

        if (!$orderHour || $orderHour->format('H:i') !== $data['hour']) {
            return $this->json(
                ['message' => 'Format d\'heure invalide. Utilisez HH:MM'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Les réservations doivent être espacées de 15 minutes.
        $minutes = (int) $orderHour->format('i');

        if ($minutes % 15 !== 0) {
            return $this->json(
                ['message' => 'Les réservations doivent être effectuées sur un créneau de 15 minutes'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Vérification de la capacité déjà réservée sur ce créneau.
        $alreadyReserved = $this->bookingRepository->getTotalGuestsForSlot(
            $restaurant,
            $orderDate,
            $orderHour
        );

        if (
            $alreadyReserved + $guestNumber
            > $restaurant->getMaxGuest()
        ) {
            return $this->json(
                [
                    'message' => 'Le restaurant est complet pour ce créneau',
                    'capacity' => $restaurant->getMaxGuest(),
                    'alreadyReserved' => $alreadyReserved,
                    'requested' => $guestNumber,
                ],
                Response::HTTP_CONFLICT
            );
        }

        $booking = new Booking();

        $booking->setGuestNumber($guestNumber);
        $booking->setOrderDate($orderDate);
        $booking->setOrderHour($orderHour);
        $booking->setRestaurant($restaurant);
        $booking->setClient($user);
        $booking->setAllergy($data['allergy'] ?? null);
        $booking->setCreatedAt(new DateTimeImmutable());

        $this->manager->persist($booking);
        $this->manager->flush();

        return $this->json(
            [
                'message' => 'Réservation créée avec succès',
                'booking' => [
                    'id' => $booking->getId(),
                    'date' => $booking->getOrderDate()->format('Y-m-d'),
                    'hour' => $booking->getOrderHour()->format('H:i'),
                    'guestNumber' => $booking->getGuestNumber(),
                    'allergy' => $booking->getAllergy(),
                    'restaurantId' => $restaurant->getId(),
                ],
            ],
            Response::HTTP_CREATED
        );
    }

    /**
     * Récupérer les réservations de l'utilisateur connecté.
     */
    #[Route('/', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/bookings/',
        summary: 'Récupérer mes réservations',
        description: 'Récupère toutes les réservations de l\'utilisateur connecté, triées par date et heure',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des réservations',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 42),
                            new OA\Property(property: 'date', type: 'string', example: '2024-12-25'),
                            new OA\Property(property: 'hour', type: 'string', example: '19:30'),
                            new OA\Property(property: 'guestNumber', type: 'integer', example: 4),
                            new OA\Property(property: 'allergy', type: 'string', example: 'Pas de gluten', nullable: true),
                            new OA\Property(property: 'restaurantId', type: 'integer', example: 1),
                        ]
                    )
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Utilisateur non authentifié',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Utilisateur non authentifié'
                        ),
                    ]
                )
            ),
        ]
    )]
    public function list(
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $bookings = $this->bookingRepository->findBy(
            ['client' => $user],
            ['orderDate' => 'ASC', 'orderHour' => 'ASC']
        );

        $result = [];

        foreach ($bookings as $booking) {
            $result[] = [
                'id' => $booking->getId(),
                'date' => $booking->getOrderDate()->format('Y-m-d'),
                'hour' => $booking->getOrderHour()->format('H:i'),
                'guestNumber' => $booking->getGuestNumber(),
                'allergy' => $booking->getAllergy(),
                'restaurantId' => $booking->getRestaurant()->getId(),
            ];
        }

        return $this->json($result);
    }

    /**
     * Récupérer une réservation.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[OA\Get(
        path: '/api/bookings/{id}',
        summary: 'Récupérer les détails d\'une réservation par ID',
        description: 'Récupère les détails d\'une réservation spécifique (accessible uniquement par le propriétaire)',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID de la réservation',
                schema: new OA\Schema(type: 'integer'),
                example: 42
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Détails de la réservation',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 42),
                        new OA\Property(property: 'date', type: 'string', example: '2024-12-25'),
                        new OA\Property(property: 'hour', type: 'string', example: '19:30'),
                        new OA\Property(property: 'guestNumber', type: 'integer', example: 4),
                        new OA\Property(property: 'allergy', type: 'string', example: 'Pas de gluten', nullable: true),
                        new OA\Property(property: 'restaurantId', type: 'integer', example: 1),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Utilisateur non authentifié',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Utilisateur non authentifié'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Accès interdit (ce n\'est pas votre réservation)',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Accès interdit'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Réservation non trouvée',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Réservation introuvable'
                        ),
                    ]
                )
            ),
        ]
    )]
    public function show(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $booking = $this->bookingRepository->find($id);

        if (!$booking) {
            return $this->json(
                ['message' => 'Réservation introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Un utilisateur ne peut consulter que ses propres réservations.
        if ($booking->getClient() !== $user) {
            return $this->json(
                ['message' => 'Accès interdit'],
                Response::HTTP_FORBIDDEN
            );
        }

        return $this->json([
            'id' => $booking->getId(),
            'date' => $booking->getOrderDate()->format('Y-m-d'),
            'hour' => $booking->getOrderHour()->format('H:i'),
            'guestNumber' => $booking->getGuestNumber(),
            'allergy' => $booking->getAllergy(),
            'restaurantId' => $booking->getRestaurant()->getId(),
        ]);
    }

    /**
     * Supprimer une réservation.
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/bookings/{id}',
        summary: 'Supprimer une réservation par ID',
        description: 'Supprime une réservation (accessible uniquement par le propriétaire)',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID de la réservation',
                schema: new OA\Schema(type: 'integer'),
                example: 42
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Réservation supprimée avec succès',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Réservation supprimée avec succès'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Utilisateur non authentifié',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Utilisateur non authentifié'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Accès interdit (ce n\'est pas votre réservation)',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Accès interdit'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Réservation non trouvée',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Réservation introuvable'
                        ),
                    ]
                )
            ),
        ]
    )]
    public function delete(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $booking = $this->bookingRepository->find($id);

        if (!$booking) {
            return $this->json(
                ['message' => 'Réservation introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Un utilisateur ne peut supprimer que ses propres réservations.
        if ($booking->getClient() !== $user) {
            return $this->json(
                ['message' => 'Accès interdit'],
                Response::HTTP_FORBIDDEN
            );
        }

        $this->manager->remove($booking);
        $this->manager->flush();

        return $this->json(
            ['message' => 'Réservation supprimée avec succès']
        );
    }
}