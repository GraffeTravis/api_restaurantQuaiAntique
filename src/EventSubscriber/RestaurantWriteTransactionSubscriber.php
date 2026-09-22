<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RestaurantWriteTransactionSubscriber implements EventSubscriberInterface
{
    private bool $active = false;

    public function __construct(private Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['begin', 0],
            KernelEvents::RESPONSE => ['finish', 0],
            KernelEvents::EXCEPTION => ['rollback', 100],
        ];
    }

    public function begin(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe()
            || !preg_match('#^/api/(bookings|restaurants)(/|$)#', $request->getPathInfo())) {
            return;
        }

        $this->connection->beginTransaction();
        $this->active = true;
        // Serialise capacity and schedule mutations before controllers read their state.
        // The project has one restaurant; stable ordering also handles moves between restaurants.
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->connection->executeQuery('SELECT id FROM restaurant ORDER BY id FOR UPDATE')->free();
        }
    }

    public function finish(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->active) {
            return;
        }
        $this->active = false;
        if ($event->getResponse()->getStatusCode() >= 400) {
            $this->connection->rollBack();
        } else {
            $this->connection->commit();
        }
    }

    public function rollback(ExceptionEvent $event): void
    {
        if ($event->isMainRequest() && $this->active) {
            $this->active = false;
            $this->connection->rollBack();
        }
    }
}
