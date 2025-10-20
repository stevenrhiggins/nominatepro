<?php

namespace App\Contracts;

interface PasswordPolicyInterface
{
    public function validate(string $password): array;
    public function score(string $password): int;
}