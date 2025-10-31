<?php

namespace App\Repositories;

use DB\SQL;
use DB\SQL\Mapper;

class EmailTokenRepository
{
    public function __construct(private SQL $db) {

    }

    private function mapper(): Mapper
    {
        return new Mapper($this->db, 'nomination_email_tokens');
    }

//    public function create(array $data): void
//    {
//        $m = $this->mapper();
//        foreach ($data as $k => $v) { $m->$k = $v; }
//        $m->save();
//    }

    public function tooFrequent(string $awardSlug, string $email, int $seconds): bool
    {
        $sql = "
            SELECT 1
            FROM nomination_email_tokens
            WHERE award_slug = ?
              AND LOWER(email) = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            LIMIT 1
        ";
        return !empty($this->db->exec($sql, [$awardSlug, strtolower($email), $seconds]));
    }

    public function existsValidHash(string $awardSlug, string $email, string $plainCode): bool
    {
        $tokenHash = hash('sha256', $plainCode);
        $m = $this->mapper();
        $m->load([
            'award_slug=? AND LOWER(email)=? AND token_hash=? AND used_at IS NULL AND expires_at >= NOW()',
            $awardSlug, strtolower($email), $tokenHash
        ]);
        return !$m->dry();
    }

    public function markAllUnusedAsUsed(string $awardSlug, string $email, string $reason = 'superseded'): int
    {
        $emailLower = strtolower($email);
        $db = $this->db; // PDO or F3 DB\SQL

        // optional: wrap caller in a transaction
        $sql = "UPDATE nomination_email_tokens
            SET used_at = NOW(),
                used_reason = :reason
            WHERE award_slug = :award
              AND email = :email
              AND used_at IS NULL";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':reason' => $reason,
            ':award'  => $awardSlug,
            ':email'  => $emailLower,
        ]);
        return $stmt->rowCount(); // how many were invalidated
    }

    /**
     * Keep this for when a user submits the code (you *do* have the plain code then).
     */
    public function markUsed(string $awardSlug, string $email, string $plainCode): void
    {
        $tokenHash = hash('sha256', $plainCode);
        $m = $this->mapper();
        $m->load([
            'award_slug=? AND email_lower=? AND token_hash=? AND used_at IS NULL',
            $awardSlug, strtolower($email), $tokenHash
        ]);
        if (!$m->dry()) {
            $m->used_at = date('Y-m-d H:i:s');
            $m->used_reason = 'redeemed';
            $m->save();
        }
    }

    public function createToken(
        string $awardSlug,
        string $email,
        ?string $plainCode = null,
        int $ttlMinutes = 30,
        string $ip = '',
        string $ua = ''
    ): string {
        $email = strtolower(trim($email));
        $plainCode ??= str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = hash('sha256', $plainCode);

        // Important: expire previous unused first
        $this->expireUnused($awardSlug, $email);

        $m = $this->mapper();
        $m->award_slug = $awardSlug;
        $m->email      = $email;
        $m->token_hash = $hash;
        $m->expires_at = (new \DateTimeImmutable("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');
        $m->ip         = $ip;
        $m->ua         = $ua;
        $m->attempts   = 0;
        $m->used_at    = null;
        $m->created_at = date('Y-m-d H:i:s');
        $m->save();

        return $plainCode;
    }

    public function expireUnused(string $awardSlug, string $email): void
    {
        $this->db->exec(
            "UPDATE nomination_email_tokens
             SET expires_at = NOW()
             WHERE award_slug=? AND email=LOWER(?) AND used_at IS NULL",
            [$awardSlug, strtolower($email)]
        );
    }

    public function consume(string $awardSlug, string $email, string $plainCode): void
    {
        $email = strtolower(trim($email));
        $hash  = hash('sha256', preg_replace('/\s+/', '', $plainCode) ?? '');
        $m = $this->m();
        $m->load([
            'award_slug=? AND email=? AND token_hash=? AND used_at IS NULL',
            $awardSlug, $email, $hash
        ]);
        if (!$m->dry()) {
            $m->used_at = date('Y-m-d H:i:s');
            $m->save();
        }
    }


}
