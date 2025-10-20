<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AwardRepositoryInterface;
use Base;
use DB\SQL;
use DB\SQL\Mapper;

final class AwardRepository implements AwardRepositoryInterface
{
    public function __construct(private Base $f3) {}

    private function db(): SQL
    {
        /** @var SQL $db */
        $db = $this->f3->DB;
        return $db;
    }

    private function mapper(): Mapper
    {
        return new Mapper($this->db(), 'awards');
    }

    public function awardNameExistsQuery(string $cpSlug, string $awardName): bool
    {
        $m = new Mapper($this->db(), 'awards');
        $m->load(['cp_slug=? AND LOWER(award_name)=LOWER(?)', $cpSlug, $awardName]);
        return !$m->dry();
    }

    // ------------------------ Counts / Lists ------------------------

    public function countActiveByCpSlugQuery(string $cpSlug): int
    {
        $row = $this->db()->exec(
            'SELECT COUNT(*) AS c
               FROM awards
              WHERE cp_slug = ?
                AND (nomination_start_date <= CURRENT_DATE())
                AND (nomination_end_date   >= CURRENT_DATE())',
            [$cpSlug]
        );
        return (int)($row[0]['c'] ?? 0);
    }

    public function countByCpSlug(string $cpSlug): int
    {
        $row = $this->db()->exec(
            'SELECT COUNT(*) AS c FROM awards WHERE cp_slug = ?',
            [$cpSlug]
        );
        return (int)($row[0]['c'] ?? 0);
    }

    public function countNominationsByStatus(string $awardSlug, string $status): int
    {
        $where  = 'award_slug = ?';

        switch ($status) {
            case 'completed':
                $where .= ' AND completed_at IS NOT NULL AND environment != "demo"';
                break;
            case 'ongoing':
                $where .= ' AND completed_at IS NULL AND environment != "demo"';
                break;
            case 'all':
                $where .= ' AND environment != "demo"';
                break;
            case 'demo':
                $where .= ' AND environment = "demo"';
                break;
            default:
                // Fallback to ongoing if somehow called with an unexpected status
                $where .= ' AND completed_at IS NULL AND environment != "demo"';
                break;
        }

        $row = $this->db()->exec("SELECT COUNT(*) as c FROM nominations WHERE $where ORDER BY submitted_at DESC", [$awardSlug]) ?: [];
        return (int)($row[0]['c'] ?? 0);
    }

    public function countAllNominations(string $awardSlug): ?int
    {
        $row = $this->db()->exec(
            'SELECT COUNT(*) as c FROM nominations WHERE award_slug = :key',
            [$awardSlug]);
        return (int)($row[0]['c'] ?? 0);
    }

    public function countQuestionsByType(string $awardSlug, string $type): int
    {
        $row = $this->db()->exec(
            'SELECT COUNT(question_id) AS c
               FROM questions             
              WHERE award_slug = ? AND type = ?',
            [$awardSlug, $type]
        );
        return (int)($row[0]['c'] ?? 0);
    }

    public function countSectionsByAwardSlug(string $awardSlug): int
    {
        $row = $this->db()->exec(
            'SELECT COUNT(id) AS c
               FROM sections 
              WHERE award_slug = ?',
            [$awardSlug]
        );
        return (int)($row[0]['c'] ?? 0);
    }

    public function createAward(): ?array
    {
        // Pull and sanitize inputs once
        $cpSlug    = (string) ($this->f3->get('SESSION.cp_slug') ?? '');
        $awardName = trim((string) $this->f3->get('POST.award_name'));
        $awardName = $this->f3->clean($awardName);

        if ($cpSlug === '' || $awardName === '') {
            return null;
        }

        $m    = new Mapper($this->db(), 'awards');
        $slug = bin2hex(random_bytes(20));
        $accessToken = bin2hex(random_bytes(8));
        $now  = date('Y-m-d H:i:s');

        $m->copyFrom([
            'cp_slug'       => $cpSlug,
            'award_slug'    => $slug,
            'access_token'  => $accessToken,
            'award_name'    => $awardName,
            'date_created'  => $now,
            'date_edited'   => $now,

        ]);

        $m->save();

        // Mapper will hydrate the PK if mapped (e.g., 'award_id')
        $id = isset($m->award_id) ? (int) $m->award_id : null;

        return $id ? ['id' => $id, 'slug' => $slug] : null;
    }

    public function createUpdateNominatorNomineeContactQuery(string $awardSlug, string $type, array $data): ?int
    {
        $m = new Mapper($this->db(), 'nominators_nominees_forms');

        $isUpdate = filter_var($data['update'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isUpdate && !empty($data['id'])) {
            $m->load(['id = ?', (int)$data['id']]);
        } elseif ($isUpdate) {
            $m->load(['award_slug = ? AND nominator_nominee = ?', $awardSlug, $type]);
        }

        if ($isUpdate && $m->dry()) {
            // return null; // or fall through to create
        }

        $m->copyfrom($data);

        $m->award_slug         = $awardSlug;
        $m->nominator_nominee  = $type;

        $now = date('Y-m-d H:i:s');
        if ($m->dry()) {
            // insert
            $m->date_created = $now;
        } else {
            // update
            $m->date_edited  = $now;
        }

        $m->save();

        $id = isset($m->id) ? (int)$m->id : null;
        return $id ?: null;
    }

    public function fetchActiveAwardsByCpSlug(string $cpSlug): array
    {
        return $this->db()->exec(
            'SELECT awards.*, COUNT(nominations.award_slug) AS nomination_count
       FROM awards
       LEFT JOIN nominations ON nominations.award_slug = awards.award_slug
      WHERE awards.cp_slug = ?
        AND (awards.nomination_start_date <= CURRENT_DATE())
        AND (awards.nomination_end_date   >= CURRENT_DATE())
      GROUP BY awards.award_slug
      ORDER BY awards.date_edited DESC',
            [$cpSlug]
        ) ?: [];
    }

    /** Gets specific columns and nomination information in all awards for a control panel*/
    public function fetchAllAwardsQuery(string $cpSlug): array
    {
        return $this->db()->exec('SELECT awards.award_name, awards.award_slug, awards.nomination_start_date, awards.nomination_end_date, awards.date_edited, 
        COUNT(nominations.award_slug) as nomination_count FROM awards 
        LEFT JOIN nominations ON awards.award_slug = nominations.award_slug WHERE awards.cp_slug=? GROUP BY awards.award_slug',[$cpSlug]
        ) ?: [];
    }

    public function fetchAwardByAwardSlug(string $awardSlug): array
    {
        return $this->db()->exec(
            'SELECT * FROM awards WHERE award_slug = ? LIMIT 1',
            [$awardSlug]
        ) ?: [];
    }

    public function fetchAwardByAwardSlugMapperVersionQuery(string $awardSlug): Mapper
    {
        $award = new Mapper($this->db(), 'awards');
        $award->select('date_edited');
        $award->load(['award_slug = ?', $awardSlug], ['limit' => 1]);
        return $award;
    }

    public function fetchAwardLastEdited(string $awardSlug): ?string
    {
        // Map the 'awards' table
        $award = new Mapper($this->db(), 'awards');

        // Only fetch the timestamp columns we care about
        $award->select('date_edited');

        // Load one row by slug
        $award->load(['award_slug = ?', $awardSlug], ['limit' => 1]);

        if ($award->dry()) {
            return null;
        }

        // Prefer date_edited, fall back to updated_at, then modified_at
        $candidates = [
            $award->date_edited ?? null,
        ];

        foreach ($candidates as $val) {
            if ($val !== null && $val !== '') {
                return (string)$val;
            }
        }

        return null;
    }

    /** Gets all columns in all awards for a control panel*/
    public function fetchAwardsQuery(string $cpSlug): array
    {
        return $this->db()->exec(
            'SELECT * FROM awards WHERE cp_slug = ? ORDER BY award_name',
            [$cpSlug]);
    }

    public function fetchDeletedRecordsQuery(string $cpSlug): array
    {
        return $this->db()->exec(
            'SELECT * FROM deleted_records WHERE cp_slug = ? ORDER BY id DESC',
            [$cpSlug]
        ) ?: [];
    }

    public function fetchNominationDatesBySlug(string $awardSlug): ?array
    {
        $rows = $this->db()->exec(
            'SELECT nomination_start_date, nomination_end_date
         FROM awards
         WHERE award_slug = :slug
         LIMIT 1',
            [':slug' => $awardSlug]
        );
        return $rows[0] ?? null;
    }

    public function fetchSubmittedNominationsCount(string $awardSlug): array
    {
        return $this->db()->exec(
            'SELECT COUNT(submitted_at) AS submitted
               FROM nominations
              WHERE award_slug = ? AND submitted_at IS NOT NULL',
            [$awardSlug]
        ) ?: [];
    }

    public function fetchNominationsByStatus(string $awardSlug, string $status): array
    {
        $where  = 'award_slug = ?';

        switch ($status) {
            case 'completed':
                $where .= ' AND completed_at IS NOT NULL AND environment != "demo"';
                break;
            case 'ongoing':
                $where .= ' AND completed_at IS NULL AND environment != "demo"';
                break;
            case 'all':
                $where .= ' AND environment != "demo"';
                break;
            case 'demo':
                $where .= ' AND environment = "demo"';
                break;
            default:
                // Fallback to ongoing if somehow called with an unexpected status
                $where .= ' AND completed_at IS NULL AND environment != "demo"';
                break;
        }

        return $this->db()->exec("SELECT * FROM nominations WHERE {$where} ORDER BY submitted_at DESC", [$awardSlug]) ?: [];
    }

    public function fetchNominatorNomineeContactInformation(string $awardSlug, string $type): ?array
    {
        return $this->db()->exec(
            'SELECT * FROM nominators_nominees_forms 
         WHERE award_slug=? AND nominator_nominee=? LIMIT 1',
        [$awardSlug, $type]
        );
    }

    public function fetchSponsorLogoQuery(string $awardSlug): ?array
    {
        return $this->db()->exec(
            'SELECT sponsor_name, sponsor_logo, sponsor_link, sponsor_acknowledgement FROM awards 
         WHERE award_slug=? LIMIT 1',
            [$awardSlug]
        );
    }

    public function updateNominationDates(string $awardSlug, string $startDate, string $endDate): void
    {
        $this->db()->exec(
            'UPDATE awards
         SET nomination_start_date = ?, nomination_end_date = ?
         WHERE award_slug = ?',
            [$startDate, $endDate, $awardSlug]
        );
    }

    // ------------------------ Persistence API used by the Service ------------------------

    public function save(Mapper $award): void
    {
        $award->save();
    }

    public function updateBySlug(string $awardSlug, array $data, array $allowed): bool
    {
        $m = $this->mapper();
        $m->load(['award_slug = ?', $awardSlug]);
        if ($m->dry()) return false;

        $data = array_intersect_key($data, array_flip(array_merge($allowed, ['date_edited'])));
        if (empty($data)) {
            // Nothing to change; consider this a no-op success or return false
            return true;
        }
        $m->copyFrom($data);
        $this->save($m);
        return true;
    }
}
