<?php
declare(strict_types=1);

namespace App\Contracts;

interface UserRepositoryInterface
{
    public function findForLogin(string $username, string $context);

    public function touchLastLogin(?int $id);

    public function findByEmail(string $email): ?array;
    public function updatePassword(string $slug, string $passwordHash): void;
}