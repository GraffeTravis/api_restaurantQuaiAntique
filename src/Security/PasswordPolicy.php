<?php

namespace App\Security;

final class PasswordPolicy
{
    public static function isValid(mixed $password): bool
    {
        return is_string($password) && mb_strlen($password) >= 12 && strlen($password) <= 72;
    }
}
