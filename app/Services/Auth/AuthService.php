<?php
declare(strict_types=1);

namespace App\Services\Auth;


use App\Contracts\UserRepositoryInterface;
use Base;

class AuthResult
{
    public bool $ok;
    public ?object $user;

    public function __construct(bool $ok, ?object $user = null)
    {
        $this->ok = $ok;
        $this->user = $user;
    }
}

final class AuthService
{
    private Base $f3;

    public function __construct(Base $f3, private UserRepositoryInterface $users)
    {
        $this->f3 = $f3;
    }

    public function validateCredentials(string $username, string $password, string $context): AuthResult
    {
        $user = $this->users->findForLogin($username, $context);
        if (!$user || !password_verify($password, $user->password)) {
            return new AuthResult(false, null);
        }
        return new AuthResult(true, $user);
    }

    public function registerLogin(object $user): void
    {
        $this->users->touchLastLogin($user->id ?? null);
    }
}
