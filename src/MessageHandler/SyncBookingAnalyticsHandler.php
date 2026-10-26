<?php

namespace App\MessageHandler;

use App\Message\SyncBookingAnalytics;
use App\Repository\BookingAnalyticsRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncBookingAnalyticsHandler
{
    public function __construct(private BookingAnalyticsRepository $repository)
    {
    }

    public function __invoke(SyncBookingAnalytics $message): void
    {
        $this->repository->synchronize($message->snapshot);
    }
}
