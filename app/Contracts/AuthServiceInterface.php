<?php

namespace App\Contracts;

use App\Entities\User;

interface AuthServiceInterface {
    public function login(string $username, string $password, string $context): LoginResult;
}

final class LoginResult {
    public function __construct(
        public readonly bool $ok,
        public readonly ?User $user = null,
        public readonly ?string $error = null
    ) {}
    public static function success(User $u): self { return new self(true, $u, null); }
    public static function failure(string $msg): self { return new self(false, null, $msg); }
}
