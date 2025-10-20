<?php
namespace App\Repositories;

use App\Contracts\PasswordResetRepositoryInterface;
use DateTimeImmutable;
use DB\SQL;

final class PasswordResetRepository implements PasswordResetRepositoryInterface {
    public function __construct(private SQL $db) {}

    public function create(int $userId, string $selector, string $verifierHash, DateTimeImmutable $expiresAt): void {
        $this->db->exec(
            'INSERT INTO password_resets (user_id, selector, verifier_hash, requested_at, expires_at)
             VALUES (?, ?, ?, NOW(), ?)',
            [$userId, $selector, $verifierHash, $expiresAt->format('Y-m-d H:i:s')]
        );
    }

    public function findValidBySelector(string $selector): ?array {
        $rows = $this->db->exec(
            'SELECT * FROM password_resets
             WHERE selector = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [$selector]
        );
        return $rows[0] ?? null;
    }

    public function markUsed(int $id): void {
        $this->db->exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ?', [$id]);
    }

    public function purgeExpired(): int {
        $res = $this->db->exec('DELETE FROM password_resets WHERE (expires_at <= NOW()) OR (used_at IS NOT NULL)');
        return $res->count(); // F3 returns PDOStatement wrapper
    }
}
