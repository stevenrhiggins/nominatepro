<?php
namespace App\Services;

use App\Contracts\PasswordPolicyInterface;

final class PasswordPolicy implements PasswordPolicyInterface
{
    public function validate(string $p): array {
        $e = [];
        if (strlen($p) < 12)                       $e[] = 'Password must be at least 12 characters.';
        if (!preg_match('/[A-Z]/', $p))            $e[] = 'Include at least one uppercase letter.';
        if (!preg_match('/[a-z]/', $p))            $e[] = 'Include at least one lowercase letter.';
        if (!preg_match('/\d/', $p))               $e[] = 'Include at least one number.';
        if (!preg_match('/[^A-Za-z0-9]/', $p))     $e[] = 'Include at least one symbol.';
        return $e;
    }

    public function score(string $p): int {
        $s  = 0;
        $s += (int)!!preg_match('/[A-Z]/', $p);
        $s += (int)!!preg_match('/[a-z]/', $p);
        $s += (int)!!preg_match('/\d/', $p);
        $s += (int)!!preg_match('/[^A-Za-z0-9]/', $p);
        if (strlen($p) >= 16 && $s < 4) $s++;
        if (strlen($p) < 12)            $s = max(0, $s - 1);
        return min(4, $s);
    }
}
