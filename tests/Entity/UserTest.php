<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public static function provideEmail(): \Generator
    {
        yield ['Thomas@test.com'];
        yield ['Eric@test.com'];
        yield ['Marie@test.com'];
    }

    #[DataProvider('provideEmail')]
    public function testEmailSetter(string $email): void
    {
        $user = new User();
        $user->setEmail($email);

        self::assertSame($email, $user->getEmail());
    }

    public function testUserGetsSecurityDefaultsOnCreation(): void
    {
        $user = new User();

        self::assertNull($user->getApiToken());
        $token = $user->issueApiToken();
        self::assertSame(hash('sha256', $token), $user->getApiToken());
        self::assertNotSame($token, $user->getApiToken());
        self::assertGreaterThan(new \DateTimeImmutable(), $user->getApiTokenExpiresAt());
        $user->revokeApiToken();
        self::assertNull($user->getApiToken());
        self::assertNull($user->getApiTokenExpiresAt());
        self::assertNotNull($user->getUuid());
        self::assertContains('ROLE_USER', $user->getRoles());
    }
}
