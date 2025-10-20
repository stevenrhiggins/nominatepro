<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\SessionInterface;
use Base;

class SessionManager implements SessionInterface
{
    public function __construct(private Base $f3) {}

    public function store(string $controller, array $data): void
    {
        $this->f3->set('SESSION.logged', true);
        $this->set($controller.'_id',           $data[$controller.'_id'] ?? null);
        $this->set($controller.'_slug',         $data[$controller.'_slug'] ?? null);
        $this->set($controller.'_name',         $data[$controller.'_name'] ?? ($data['organization_name'] ?? null));
        $this->set($controller.'_contact_name', $data[$controller.'_contact_name'] ?? null);

        $logoKey = $controller.'_logo';
        if (!empty($data[$logoKey])) {
            $this->set($logoKey, $data[$logoKey]);
        } elseif (!empty($data['organization_logo']) && $controller === 'cp') {
            $this->set('cp_logo', $data['organization_logo']);
        }
    }

    public function destroy(): void
    {
        $this->f3->clear('SESSION');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function set(string $key, mixed $value): void
    {
        if ($value !== null) {
            $this->f3->set('SESSION.'.$key, $value);
        }
    }

    public function unset(string $key): void
    {
        // TODO: Implement unset() method.
    }

    public function unsetMany(array $keys): void
    {
        // TODO: Implement unsetMany() method.
    }

    public function get(string $key)
    {
        // TODO: Implement get() method.
    }
}
