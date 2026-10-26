<?php

namespace App\Controller;

use App\Repository\RestaurantRepository;
use App\Service\BookingStatistics;
use App\Service\BookingStatisticsFilter;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class BookingStatisticsController extends AbstractController
{
    #[Route('/api/admin/statistics/bookings', name: 'app_api_admin_statistics_bookings', methods: ['GET'])]
    #[OA\Tag(name: 'Statistics')]
    #[OA\Get(summary: 'Statistiques des réservations actives par date de repas (Europe/Paris)', parameters: [
        new OA\Parameter(name: 'from', in: 'query', description: 'Inclus, premier jour du mois par défaut ; 366 jours maximum.', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', description: 'Inclus, aujourd’hui par défaut.', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'restaurantId', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        new OA\Parameter(name: 'granularity', in: 'query', schema: new OA\Schema(type: 'string', enum: ['day', 'week', 'month'], default: 'day')),
    ], responses: [
        new OA\Response(response: 200, description: 'Totaux, évolution, services, jours ISO (1=lundi), créneaux et état de synchronisation.'),
        new OA\Response(response: 400, description: 'Filtres invalides'),
        new OA\Response(response: 401, description: 'Authentification requise'),
        new OA\Response(response: 403, description: 'Administrateur requis'),
        new OA\Response(response: 404, description: 'Restaurant introuvable'),
        new OA\Response(response: 503, description: 'Statistiques temporairement indisponibles'),
    ])]
    public function __invoke(Request $request, RestaurantRepository $restaurants, BookingStatistics $statistics, LoggerInterface $logger): JsonResponse
    {
        try {
            $filter = BookingStatisticsFilter::fromQuery($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], 400);
        }
        if (!$restaurants->find($filter->restaurantId)) {
            return $this->json(['message' => 'Restaurant introuvable'], 404);
        }
        try {
            return $this->json($statistics->calculate($filter), headers: ['Cache-Control' => 'no-store']);
        } catch (\Throwable $exception) {
            $logger->error('Booking statistics unavailable.', ['exception' => $exception]);

            return $this->json(['message' => 'Les statistiques sont temporairement indisponibles. Réessayez plus tard.'], 503, ['Retry-After' => '30', 'Cache-Control' => 'no-store']);
        }
    }
}
