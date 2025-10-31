<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\UserRepositoryInterface;
use Base;
use DB\SQL;
use DB\SQL\Mapper;

class UserRepository implements UserRepositoryInterface
{
    public function __construct(private Base $f3) {}

    private function db(): SQL
    {
        /** @var SQL $db */
        $db = $this->f3->get('DB');
        return $db;
    }

    public function findForLogin(string $username, string $context): ?object
    {
        $mapper = new Mapper($this->db(), 'login');
        if ($context === 'cp') {
            $mapper->load(['username=? AND cp_slug IS NOT NULL', $username]);
        } else {
            $mapper->load(['username=?', $username]);
        }
        return $mapper->dry() ? null : $mapper; // return Mapper as user object
    }

    public function touchLastLogin(?int $id): void
    {
        if (!$id) return;
        $mapper = new Mapper($this->db(), 'login');
        $mapper->load(['id=?', $id]);
        if (!$mapper->dry()) {
            $mapper->last_login = date('Y-m-d');
            $mapper->save();
        }
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db()->exec(
            'SELECT * FROM `login` WHERE `email` = ?',
            [$email]);
    }

    public function updatePassword(string $slug, string $passwordHash): void
    {
        $mapper = new Mapper($this->db(), 'login');
        $mapper->load(['cp_slug=?', $slug]);
        $mapper->password = $passwordHash;
        $mapper->save();
    }
}
