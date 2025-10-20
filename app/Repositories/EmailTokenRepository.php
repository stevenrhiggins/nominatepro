<?php
namespace App\Repositories;

use PDO;

class EmailTokenRepository
{
    public function __construct(private $db) {}

    public function tooFrequent(string $awardSlug, string $email, int $seconds): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM nomination_email_tokens
            WHERE award_slug = :slug AND email = :email
              AND created_at >= (NOW() - INTERVAL :secs SECOND)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->bindValue(':slug', $awardSlug);
        $stmt->bindValue(':email', mb_strtolower($email));
        $stmt->bindValue(':secs', $seconds, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    public function create(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO nomination_email_tokens
            (award_slug,email,token_hash,expires_at,ip,ua,created_at)
            VALUES (:slug,:email,:hash,:expires,:ip,:ua,NOW())
        ");
        $stmt->execute([
            ':slug'   => $data['award_slug'],
            ':email'  => mb_strtolower($data['email']),
            ':hash'   => $data['token_hash'],
            ':expires'=> $data['expires_at'],
            ':ip'     => $data['ip'] ?? '',
            ':ua'     => $data['ua'] ?? '',
        ]);
    }

    public function findValid(string $awardSlug, string $email, string $tokenHash): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, award_slug, email, token_hash, expires_at, used_at
            FROM nomination_email_tokens
            WHERE award_slug = :slug
              AND email      = :email
              AND token_hash = :hash
              AND used_at IS NULL
              AND expires_at >= NOW()
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':slug'  => $awardSlug,
            ':email' => mb_strtolower($email),
            ':hash'  => $tokenHash,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markUsed(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE nomination_email_tokens SET used_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
}
