<?php

namespace App\Message;

final readonly class SyncBookingAnalytics
{
    public function __construct(public array $snapshot)
    {
    }
}
