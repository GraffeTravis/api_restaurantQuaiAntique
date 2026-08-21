<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

        $this->assertSame($email, $user->getEmail());
    }

    public function testTheAutomaticApiTokenSettingWhenAnUserIsCreated(): void
    {
        $user = new User();
        $this->assertNotNull($user->getApiToken());
    }
}