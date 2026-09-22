<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

final class AuthRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['check', 16]];
    }

    public function check(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $limits = ['/api/login' => [20, '15 minutes'], '/api/registration' => [5, '1 hour'], '/api/account/password' => [5, '15 minutes']];
        $path = $request->getPathInfo();
        if (!$event->isMainRequest() || $request->isMethodSafe() || !isset($limits[$path])) {
            return;
        }

        $storage = new CacheStorage(new DoctrineDbalAdapter($this->connection, 'auth', 0, ['db_table' => 'auth_rate_limits']));
        $store = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? new DoctrineDbalPostgreSqlStore($this->connection) : new FlockStore();
        $factory = new RateLimiterFactory([
            'id' => $path, 'policy' => 'fixed_window', 'limit' => $limits[$path][0], 'interval' => $limits[$path][1],
        ], $storage, new LockFactory($store));
        $limit = $factory->create(hash('sha256', $request->getClientIp() ?? 'unknown'))->consume();
        if (!$limit->isAccepted()) {
            $event->setResponse(new JsonResponse(
                ['message' => 'Trop de tentatives. Réessayez plus tard.'], 429,
                ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]
            ));
        }
    }
}
