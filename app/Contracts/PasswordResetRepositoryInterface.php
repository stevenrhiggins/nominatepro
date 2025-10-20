<?php
namespace App\Contracts;


interface PasswordResetRepositoryInterface {

    public function create(int $userId, string $selector, string $verifierHash, \DateTimeImmutable $expiresAt): void;

    public function findValidBySelector(string $selector): ?array;

    public function markUsed(int $id): void;

    public function purgeExpired(): int;

}
