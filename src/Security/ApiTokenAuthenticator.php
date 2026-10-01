<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;


class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UserRepository $repository,
        #[Autowire('%env(CORS_ALLOW_ORIGIN)%')]
        private string $allowedOrigins,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (in_array($request->getPathInfo(), ['/api/login', '/api/registration'], true)) {
            return false;
        }

        return $request->headers->has('X-AUTH-TOKEN')
            || $request->cookies->has('__Host-quai_session')
            || $request->cookies->has('quai_session');
    }

    public function authenticate(Request $request): Passport
    {
        $apiToken = $request->headers->get('X-AUTH-TOKEN');
        $cookieAuth = false;
        if (!is_string($apiToken) || $apiToken === '') {
            $apiToken = $request->cookies->get('__Host-quai_session') ?? $request->cookies->get('quai_session');
            $cookieAuth = is_string($apiToken) && $apiToken !== '';
        }

        if (!is_string($apiToken) || !preg_match('/^[a-f0-9]{64}$/D', $apiToken)) {
            throw new CustomUserMessageAuthenticationException('No API token provided');
        }

        if ($cookieAuth && !$request->isMethodSafe()) {
            $origin = $request->headers->get('Origin');
            $originPattern = '~'.str_replace('~', '\\~', $this->allowedOrigins).'~D';
            if (!is_string($origin) || @preg_match($originPattern, $origin) !== 1) {
                throw new CustomUserMessageAuthenticationException('Request origin is not allowed');
            }
        }

        $user = $this->repository->findOneBy(['apiToken' => hash('sha256', $apiToken)]);
        if (null === $user || $user->getApiTokenExpiresAt() === null
            || $user->getApiTokenExpiresAt() <= new \DateTimeImmutable()) {
            throw new UserNotFoundException();
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['message' => strtr($exception->getMessageKey(), $exception->getMessageData())],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
