<?php
namespace App\Repositories;

use DB\SQL;

class NominationRepository
{
    /** @var SQL */
    private $db;

    public function __construct(SQL $db) { $this->db = $db; }

    public function findActiveByAwardAndEmail(string $awardSlug, string $email): ?array
    {
        $rows = $this->db->exec(
            'SELECT * FROM nominations
             WHERE award_slug=? AND email=? AND status="in_progress"
             ORDER BY id DESC LIMIT 1',
            [$awardSlug, mb_strtolower($email)]
        );
        return $rows ? $rows[0] : null;
    }

    public function findLatestCompletedByAwardAndEmail(string $awardSlug, string $email): ?array
    {
        $rows = $this->db->exec(
            'SELECT * FROM nominations
             WHERE award_slug=? AND email=? AND status="completed"
             ORDER BY completed_at DESC, id DESC LIMIT 1',
            [$awardSlug, mb_strtolower($email)]
        );
        return $rows ? $rows[0] : null;
    }

    public function create(string $awardSlug, string $email, bool $needsDocs): array
    {
        $slug = $this->generateSlug($awardSlug);

        $now = date('Y-m-d H:i:s');
        // documents step is auto-marked done if not required
        $docsDone = $needsDocs ? 0 : 1;

        $this->db->exec(
            'INSERT INTO nominations
             (nomination_slug, award_slug, email, status, current_step,
              nominator_done, nominee_done, questionnaire_done, documents_done,
              needs_documents, created_at, updated_at)
             VALUES (?, ?, ?, "in_progress", "nominator", 0,0,0,?, ?, ?, ?)',
            [$slug, $awardSlug, mb_strtolower($email), $docsDone, (int)$needsDocs, $now, $now]
        );

        $rows = $this->db->exec('SELECT * FROM nominations WHERE nomination_slug=? LIMIT 1', [$slug]);
        return $rows ? $rows[0] : [];
    }

    public function markStepComplete(int $id, string $step): array
    {
        $col = $this->stepToColumn($step);
        if (!$col) return $this->getById($id);

        $this->db->exec(
            "UPDATE nominations SET {$col}=1, updated_at=? WHERE id=?",
            [date('Y-m-d H:i:s'), $id]
        );

        // recompute current_step + status
        $nom = $this->getById($id);
        $next = $this->computeNextStep($nom);
        if ($next === 'success') {
            $this->db->exec(
                'UPDATE nominations SET status="completed", current_step="success", completed_at=?, updated_at=? WHERE id=?',
                [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]
            );
        } else {
            $this->db->exec(
                'UPDATE nominations SET current_step=?, updated_at=? WHERE id=?',
                [$next, date('Y-m-d H:i:s'), $id]
            );
        }
        return $this->getById($id);
    }

    public function getById(int $id): ?array
    {
        $rows = $this->db->exec('SELECT * FROM nominations WHERE id=? LIMIT 1', [$id]);
        return $rows ? $rows[0] : null;
    }

    public function computeNextStep(array $nomination): string
    {
        // Order matters; skip documents if not needed or already done
        if (empty($nomination['nominator_done']))      return 'nominator';
        if (empty($nomination['nominee_done']))        return 'nominee';
        if (empty($nomination['questionnaire_done']))  return 'questionnaire';
        if ((int)$nomination['needs_documents'] === 1 && empty($nomination['documents_done']))
            return 'documents';
        // Everything done
        return 'success';
    }

    public function isCompleted(array $nomination): bool
    {
        return ($nomination['status'] ?? '') === 'completed';
    }

    public function stepToColumn(string $step): ?string
    {
        switch ($step) {
            case 'nominator':     return 'nominator_done';
            case 'nominee':       return 'nominee_done';
            case 'questionnaire': return 'questionnaire_done';
            case 'documents':     return 'documents_done';
            default:              return null;
        }
    }

    private function generateSlug(string $awardSlug): string
    {
        do {
            $suffix = rtrim(strtr(base64_encode(random_bytes(6)), '+/', '-_'), '=');
            $slug   = $awardSlug . '-' . strtolower($suffix);
            $exists = $this->db->exec('SELECT 1 FROM nominations WHERE nomination_slug=? LIMIT 1', [$slug]);
        } while (!empty($exists));
        return $slug;
    }
}
