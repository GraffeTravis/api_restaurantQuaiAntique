<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class AuthRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.auth_login')]
        private RateLimiterFactoryInterface $loginLimiter,
        #[Autowire(service: 'limiter.auth_registration')]
        private RateLimiterFactoryInterface $registrationLimiter,
        #[Autowire(service: 'limiter.auth_password_change')]
        private RateLimiterFactoryInterface $passwordChangeLimiter,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['check', 16]];
    }

    public function check(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $limiter = match ($path) {
            '/api/login' => $this->loginLimiter,
            '/api/registration' => $this->registrationLimiter,
            '/api/account/password' => $this->passwordChangeLimiter,
            default => null,
        };

        if (!$event->isMainRequest() || $request->isMethodSafe() || null === $limiter) {
            return;
        }

        try {
            $limit = $limiter->create(hash('sha256', $request->getClientIp() ?? 'unknown'))->consume();
        } catch (Throwable $exception) {
            $this->logger->error('Authentication rate limiter storage is unavailable.', [
                'route' => $path,
                'exception' => $exception,
            ]);

            $event->setResponse(new JsonResponse(
                ['message' => 'Service temporairement indisponible.', 'code' => 'AUTH_RATE_LIMIT_UNAVAILABLE'],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '30', 'Cache-Control' => 'no-store']
            ));

            return;
        }

        if (!$limit->isAccepted()) {
            $event->setResponse(new JsonResponse(
                ['message' => 'Trop de tentatives. Réessayez plus tard.'], 429,
                ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]
            ));
        }
    }
}
