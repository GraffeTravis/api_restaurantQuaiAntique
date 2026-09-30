<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\AuthRateLimitSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class AuthRateLimitSubscriberTest extends TestCase
{
    public function testExceededLimitReturnsJson429AndRetryAfter(): void
    {
        $loginFactory = new RateLimiterFactory(
            ['id' => 'auth_login', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '15 minutes'],
            new CacheStorage(new ArrayAdapter())
        );
        $subscriber = new AuthRateLimitSubscriber(
            $loginFactory,
            $this->createStub(RateLimiterFactoryInterface::class),
            $this->createStub(RateLimiterFactoryInterface::class),
            $this->createStub(LoggerInterface::class)
        );

        $subscriber->check($this->requestEvent());
        $event = $this->requestEvent();
        $subscriber->check($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertNotNull($response->headers->get('Retry-After'));
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame(
            ['message' => 'Trop de tentatives. Réessayez plus tard.'],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testStorageFailureReturnsLoggedJsonServiceUnavailable(): void
    {
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->expects(self::once())
            ->method('consume')
            ->willThrowException(new RuntimeException('Storage unavailable.'));

        $loginFactory = $this->createMock(RateLimiterFactoryInterface::class);
        $loginFactory->expects(self::once())
            ->method('create')
            ->willReturn($limiter);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Authentication rate limiter storage is unavailable.',
                self::callback(static fn (array $context): bool => $context['route'] === '/api/login'
                    && $context['exception'] instanceof RuntimeException)
            );

        $subscriber = new AuthRateLimitSubscriber(
            $loginFactory,
            $this->createStub(RateLimiterFactoryInterface::class),
            $this->createStub(RateLimiterFactoryInterface::class),
            $logger
        );
        $event = $this->requestEvent();

        $subscriber->check($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(
            ['message' => 'Service temporairement indisponible.', 'code' => 'AUTH_RATE_LIMIT_UNAVAILABLE'],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    private function requestEvent(): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/login', 'POST'),
            HttpKernelInterface::MAIN_REQUEST
        );
    }
}
