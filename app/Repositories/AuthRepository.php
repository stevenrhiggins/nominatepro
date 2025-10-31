<?php

namespace App\Repositories;

use Base;
use DB\SQL;
use DB\SQL\Mapper;
use function Webmozart\Assert\Tests\StaticAnalysis\null;

class AuthRepository
{
    private $f3;

    public function __construct(Base $f3)
    {
        $this->f3 = $f3;
    }

    private function db(): SQL
    {
        $db = $this->f3->get('DB');
        return $db;
    }

    public function validateCredentials(string $username, string $password, string $context): ?object
    {
        $user = $this->findForLogin($username, $context);
        if (!$user || !password_verify($password, $user->password)) {
            return null;
        }
        return $user;
    }

    public function registerLogin(object $user): void
    {
        $this->touchLastLogin($user->id ?? null);
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

}