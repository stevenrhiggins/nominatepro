<?php
declare(strict_types=1);

namespace App\Http;

use Base;
use RuntimeException;

//class CsrfGuard
//{
//    public static function issueToken(Base $f3): void
//    {
//        if (!$f3->exists('SESSION.csrf_token')) {
//            $f3->set('SESSION.csrf_token', bin2hex(random_bytes(32)));
//        }
//    }
//
//    public static function validate(Base $f3, ?string $token): void
//    {
//        $sessionToken = (string) $f3->get('SESSION.csrf_token');
//
//        if (empty($sessionToken) || empty($token) || !hash_equals($sessionToken, $token)) {
//            throw new RuntimeException('Invalid CSRF token');
//        }
//    }
//}


final class CsrfGuard
{
    public static function issueToken(Base $f3): void
    {
        if (!$f3->exists('SESSION.csrf_token')) {
            $f3->set('SESSION.csrf_token', bin2hex(random_bytes(32)));
        }
    }

    public static function validate(Base $f3, ?string $token): void
    {
        // Diagnostic breadcrumb: confirm we’re in the expected class/method
        error_log(__METHOD__ . ' reached');
        $sessionToken = (string)$f3->get('SESSION.csrf_token');
        // Normalize & sanity-check (helps catch hidden whitespace / wrong field / tampering)
        $token = is_string($token) ? trim($token) : '';
        $is64hex = static fn(string $s): bool => (strlen($s) === 64 && ctype_xdigit($s));
        if (!$is64hex($sessionToken) || !$is64hex($token)) {
            error_log("CSRF invalid format. session=" . var_export($sessionToken, true) . " post=" . var_export($token, true));
            throw new \RuntimeException('Invalid CSRF token format');
        }
        // Constant-time compare
        if (!hash_equals($sessionToken, $token)) {
            error_log('CSRF mismatch');
            throw new \RuntimeException('Invalid CSRF token');
        }
        // Optional: rotate after successful check to prevent replay
        // $f3->clear('SESSION.csrf_token');
        // self::issueToken($f3);
    }
}

