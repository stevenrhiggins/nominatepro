<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Minimal session abstraction. Your existing SessionManager can implement this.
 */
interface SessionInterface
{
    /** Remove one key from session */
    public function unset(string $key): void;

    /** Remove many keys from session */
    public function unsetMany(array $keys): void;

    /** Get a session value (or null) */
    public function get(string $key);

    /** Set a session value */
    public function set(string $key, $value): void;
}
