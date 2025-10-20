<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\QuestionRepositoryInterface;
use DB\SQL;

class QuestionRepository implements QuestionRepositoryInterface
{
    public function __construct(private SQL $db) {}

    private const QUESTION_COLUMNS = [
        'question_slug','award_slug','section_name','type','question',
        'follow_up_questions','fu_slug','input_type','required','display_status',
        'checkbox_values','checkbox_fu_trigger','radio_values','radio_fu_trigger',
        'scoring_module','open_question','numeric_question','numeric_scale',
        'response_anchors','question_value','rubric','display_order','date_edited','date_answered'
    ];

    private const FU_COLUMNS = [
        'fu_slug','fu_question_slug','fu_question','fu_input_type','fu_display_status',
        'fu_required','date_edited'
    ];


    private const SECTION_COLUMNS = [
        'cp_slug','award_slug','section_id','section_slug','section','date_edited'
    ];

    private function onlyAllowed(array $data, array $allowed): array
    {
        return array_intersect_key($data, array_flip($allowed));
    }

    // ===== Reads =====

    public function findBySlug(string $questionSlug): ?array
    {
        $rows = $this->db->exec(
            'SELECT * FROM questions WHERE question_slug = :slug LIMIT 1',
            [':slug' => $questionSlug]
        );
        return $rows[0] ?? null;
    }

// app/Repositories/QuestionRepository.php

    public function listByAward(string $awardSlug, ?string $type = null): array
    {
        if ($type) {
            return $this->db->exec(
                'SELECT
               q.question_id,
               q.question_slug,
               q.section_name,
               q.question,
               q.display_status,
               q.required,
               q.open_question,
               q.numeric_question,
               q.numeric_scale,
               q.question_value,
               q.response_anchors,
               q.fu_slug,
               q.input_type,
               q.follow_up_questions,
               q.checkbox_values,
               q.checkbox_fu_trigger,
               q.radio_values,
               q.radio_fu_trigger,
               q.scoring_module,
               q.type,
               f.fu_question,
               f.fu_input_type,
               f.fu_display_status,
               f.fu_required
             FROM questions AS q
             LEFT JOIN questions_follow_up AS f
               ON q.fu_slug = f.fu_slug
             WHERE q.award_slug = :award_slug AND q.type = :type
             ORDER BY COALESCE(q.display_order, 999999), q.question_id',
                [':award_slug' => $awardSlug, ':type' => $type]
            );
        }

        // no type filter
        return $this->db->exec(
            'SELECT
           q.question_id,
           q.question_slug,
           q.section_name,
           q.question,
           q.display_status,
           q.required,
           q.open_question,
           q.numeric_question,
           q.numeric_scale,
           q.question_value,
           q.response_anchors,
           q.fu_slug,
           q.input_type,
           q.follow_up_questions,
           q.checkbox_values,
           q.checkbox_fu_trigger,
           q.radio_values,
           q.radio_fu_trigger,
           q.scoring_module,
           q.type,
           f.fu_question,
           f.fu_input_type,
           f.fu_display_status,
           f.fu_required
         FROM questions AS q
         LEFT JOIN questions_follow_up AS f
           ON q.fu_slug = f.fu_slug
         WHERE q.award_slug = :award_slug
         ORDER BY q.section_name, q.type,
                  COALESCE(q.display_order, 999999), q.question_id',
            [':award_slug' => $awardSlug]
        );
    }

    public function fetchSectionsAndQuestionCountBySection(string $awardSlug): array
    {
        return $this->db->exec(
            'SELECT s.*, COUNT(q.question_id) AS question_count
            FROM sections s
            LEFT JOIN questions q
            ON q.award_slug = s.award_slug
            AND TRIM(q.section_name) = TRIM(s.section)
            WHERE s.award_slug = ?
            GROUP BY s.section_id
            ORDER BY s.section;',
            [$awardSlug]
        );
    }

    public function fetchSectionBySectionSlug(string $sectionSlug): array
    {
        return $this->db->exec('SELECT section FROM sections WHERE section_slug=?',
         [$sectionSlug]
        );
    }

    public function listSectionsByAward(string $awardSlug): array
    {
        return $this->db->exec(
            'SELECT * FROM sections WHERE award_slug = ? ORDER BY section',
            [$awardSlug]
        );
    }

    public function fetchSectionsByAwardSlug(string $awardSlug): array
    {
        return $this->db->exec('SELECT * FROM sections WHERE award_slug = ?', [$awardSlug]);
    }

    public function countSectionsByAward(string $awardSlug): int
    {
        $rows = $this->db->exec(
            'SELECT COUNT(DISTINCT section) AS cnt FROM sections WHERE award_slug = ?',
            [$awardSlug]
        );
        return (int)($rows[0]['cnt'] ?? 0);
    }

    public function countByAwardAndType(string $awardSlug, string $type): int
    {
        $rows = $this->db->exec(
            'SELECT COUNT(*) AS c FROM questions WHERE award_slug = :award AND type = :type',
            [':award' => $awardSlug, ':type' => $type]
        );
        return (int)($rows[0]['c'] ?? 0);
    }

    // ===== Writes (questions) =====

    public function createQuestion(string $awardSlug, array $data): int
    {
        $data['award_slug']  = $awardSlug;
        $data['date_edited'] = $data['date_edited'] ?? date('Y-m-d H:i:s');

        // Force a cryptographic slug if none provided (or always override if you prefer)
        if (empty($data['question_slug'])) {
            $data['question_slug'] = $this->newUniqueRandomSlug($awardSlug, 32); // 32 bytes -> 64 hex chars
        }

        if (!empty($data['radio_fu_trigger']) || !empty($data['checkbox_fu_trigger'])) {
            $data['follow_up_questions'] = 'on';
        }

        $clean = $this->onlyAllowed($data, self::QUESTION_COLUMNS);

        foreach (['award_slug','section_name','type','input_type'] as $req) {
            if (empty($clean[$req])) {
                throw new \InvalidArgumentException("$req is required");
            }
        }

        $cols         = array_keys($clean);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $params       = [];
        foreach ($clean as $k => $v) { $params[':' . $k] = $v; }

        // Try insert; if a freak collision occurs, regenerate and retry a few times
        $attempts = 0;
        while (true) {
            try {
                $this->db->exec(
                    'INSERT INTO questions (' . implode(',', $cols) . ')
                 VALUES (' . implode(',', $placeholders) . ')',
                    $params
                );
                break; // success
            } catch (\PDOException $e) {
                $isDuplicate = isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062;
                if ($isDuplicate && $attempts < 3) {
                    $attempts++;
                    $params[':question_slug'] = $this->newUniqueRandomSlug($awardSlug, 32);
                    continue;
                }
                throw $e;
            }
        }
        return (int) $this->db->lastInsertId();
    }

    public function createSection(string $awardSlug, array $data): int
    {
        $data['award_slug']  = $awardSlug;

        // Force a cryptographic slug if none provided (or always override if you prefer)
        if (empty($data['section_slug'])) {
            $data['section_slug'] = $this->newUniqueRandomSlug($awardSlug); // 32 bytes -> 64 hex chars
        }

        if (empty($data['section_id'])) {
            $data['section_id'] = $this->newUniqueRandomSlug($awardSlug); // 32 bytes -> 64 hex chars
        }

        $clean = $this->onlyAllowed($data, self::SECTION_COLUMNS);

        foreach (['cp_slug','award_slug','section_slug','section_id','section'] as $req) {
            if (empty($clean[$req])) {
                throw new \InvalidArgumentException("$req is required");
            }
        }

        $cols         = array_keys($clean);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $params       = [];
        foreach ($clean as $k => $v) { $params[':' . $k] = $v; }

        // Try insert; if a freak collision occurs, regenerate and retry a few times
        $attempts = 0;
        while (true) {
            try {
                $this->db->exec(
                    'INSERT INTO sections (' . implode(',', $cols) . ')
                 VALUES (' . implode(',', $placeholders) . ')',
                    $params
                );
                break; // success
            } catch (\PDOException $e) {
                $isDuplicate = isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062;
                if ($isDuplicate && $attempts < 3) {
                    $attempts++;
                    $params[':section_slug'] = $this->newUniqueRandomSlug($awardSlug, 32);
                    continue;
                }
                throw $e;
            }
        }
        return (int) $this->db->lastInsertId();
    }

    /**
     * Generate a unique, random hex slug for the given award.
     * $bytes=32 -> 64 hex chars. Increase to 48 for 96 hex chars if you like.
     */
    private function newUniqueRandomSlug(string $awardSlug, int $bytes = 32): string
    {
        // Fast existence check; odds of collision are astronomically low, but we’re thorough.
        do {
            $slug = bin2hex(random_bytes($bytes));
            $row  = $this->db->exec(
                'SELECT 1 FROM questions WHERE award_slug = :a AND question_slug = :s LIMIT 1',
                [':a' => $awardSlug, ':s' => $slug]
            );
        } while (!empty($row));
        return $slug;
    }

    public function updateQuestion(string $questionSlug, array $data): int
    {
        $data['date_edited'] = date('Y-m-d H:i:s');
        if (!empty($data['radio_fu_trigger']) || !empty($data['checkbox_fu_trigger'])) {
            $data['follow_up_questions'] = 'on';
        } else{
            $data['follow_up_questions'] = null;
            $data['fu_slug'] = null;
        }

        $clean = $this->onlyAllowed($data, self::QUESTION_COLUMNS);
        if (empty($clean)) {
            return 0;
        }

        $assign = [];
        $params = [':slug' => $questionSlug];
        foreach ($clean as $k => $v) {
            $assign[] = $k . ' = :' . $k;
            $params[':' . $k] = $v;
        }

        $res = $this->db->exec(
            'UPDATE questions SET ' . implode(',', $assign) . ' WHERE question_slug = :slug',
            $params
        );
        return (int) $res;
    }

    public function updateSection(string $sectionSlug, array $data): int
    {
        $data['date_edited'] = date('Y-m-d H:i:s');

        if($data['original_section_name']){
            $this->db->exec('UPDATE questions SET section_name=? WHERE section_name=? AND award_slug=?',
            [$data['section'],$data['original_section_name'],$data['award_slug']]
            );
        }

        $res = $this->db->exec('UPDATE sections SET section=?, date_edited=? WHERE section_slug=?', [$data['section'], $data['date_edited'], $sectionSlug]);

        return (int) $res;
    }

    public function deleteQuestion(string $questionSlug): int
    {
        $this->db->exec(
            'DELETE FROM questions_follow_up WHERE fu_question_slug = :qslug',
            [':qslug' => $questionSlug]
        );

        $res = $this->db->exec(
            'DELETE FROM questions WHERE question_slug = :slug',
            [':slug' => $questionSlug]
        );
        return (int) $res;
    }

    public function deleteSection(string $sectionSlug): int
    {
        $res = $this->db->exec('DELETE FROM sections WHERE section_slug=?',
            [$sectionSlug]
        );
        return (int) $res;
    }

    public function clone(string $awardSlug, string $originalSlug, array $originalQuestionData): int
    {
        // 1. Load the source row
        $original = $originalQuestionData;

        //Remove fields you don’t want to copy
        unset($original['id']);                 // auto-increment PK
        unset($original['question_slug']);      // must be unique
        unset($original['date_created']);       // let DB default / regenerate
        unset($original['award_slug']);       // let DB default / regenerate
        $original['date_edited'] = date('Y-m-d H:i:s');

        //this is the new award_slug
        $original['award_slug'] = $awardSlug;
        //Generate a new unique slug
        $original['question_slug'] = $this->newUniqueRandomSlug($awardSlug, 32);

        //Whitelist columns
        $clean = $this->onlyAllowed($original, self::QUESTION_COLUMNS);

        //Build INSERT
        $cols         = array_keys($clean);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $params       = [];
        foreach ($clean as $k => $v) { $params[':' . $k] = $v; }

        $this->db->exec(
            'INSERT INTO questions (' . implode(',', $cols) . ')
         VALUES (' . implode(',', $placeholders) . ')',
            $params
        );

        return (int)$this->db->lastInsertId();
    }


    public function reorder(string $awardSlug, array $slugToOrder): void
    {
        $this->db->begin();
        try {
            foreach ($slugToOrder as $slug => $order) {
                $this->db->exec(
                    'UPDATE questions
                     SET display_order = :ord, date_edited = :edit
                     WHERE award_slug = :award AND question_slug = :slug',
                    [
                        ':ord'   => (int)$order,
                        ':edit'  => date('Y-m-d H:i:s'),
                        ':award' => $awardSlug,
                        ':slug'  => $slug,
                    ]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // ===== Follow-ups =====

    public function listFollowUpsByQuestionSlug(string $questionSlug): array
    {
        return $this->db->exec(
            'SELECT * FROM questions_follow_up
             WHERE fu_question_slug = :qslug
             ORDER BY id',
            [':qslug' => $questionSlug]
        );
    }

    public function fetchFollowUpQuestionBySlug(string $fuSlug): ?array
    {
        $rows = $this->db->exec(
            'SELECT * FROM questions_follow_up WHERE fu_slug=? LIMIT 1',
            [$fuSlug]
        );
        return $rows[0] ?? null;
    }

    public function createFollowUp(string $awardSlug, array $data): int
    {
        $data['fu_question_slug'] = $data['question_slug'] ?? '';


        if (empty($data['fu_slug'])) {
            $data['fu_slug'] = $this->newUniqueRandomSlug($awardSlug); // 32 bytes -> 64 hex chars
        }

        //we need to record the fu_slug in the questions table
        try {
            $this->db->exec('UPDATE questions SET fu_slug=? WHERE question_slug=?', [$data['fu_slug'], $data['question_slug']]);
        } catch (\RuntimeException $e) {
                throw new \RuntimeException('Unable to update the  questions table.');
            }

        $clean = $this->onlyAllowed($data, self::FU_COLUMNS);

        foreach (['fu_slug','fu_question_slug','fu_question','fu_input_type'] as $req) {
            if (empty($clean[$req])) {
                throw new \InvalidArgumentException("$req is required");
            }
        }

        $cols = array_keys($clean);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $params = [];
        foreach ($clean as $k => $v) {
            $params[':' . $k] = $v;
        }

        $this->db->exec(
            'INSERT INTO questions_follow_up (' . implode(',', $cols) . ')
             VALUES (' . implode(',', $placeholders) . ')',
            $params
        );

        $row = $this->db->exec('SELECT LAST_INSERT_ID() AS id');
        return (int)($row[0]['id'] ?? 0);
    }

    public function updateFollowUpBySlug(string $fuSlug, array $data): int
    {
        $data['date_edited'] = date('Y-m-d');

        $res = $this->db->exec(
            'UPDATE questions_follow_up 
             SET fu_question = ?, 
                 fu_input_type = ?, 
                 date_edited = ? 
             WHERE fu_slug = ?',
            [$data['fu_question'], $data['fu_input_type'], $data['date_edited'], $fuSlug]
        );
        return (int) $res;
    }

    public function deleteFollowQuestionUpBySlug(string $fuSlug): int
    {
        $res = $this->db->exec(
            'DELETE FROM questions_follow_up WHERE fu_slug=?',
            [$fuSlug]
        );
        return (int) $res;
    }

    // ===== Utilities =====

    public function copyFromArray(array $questionRow, array $data): array
    {
        $clean = $this->onlyAllowed($data, self::QUESTION_COLUMNS);
        foreach ($clean as $k => $v) {
            $questionRow[$k] = $v;
        }
        return $questionRow;
    }

    public function normalizeToggle(?string $val): string
    {
        return $val === 'on' ? 'on' : '';
    }
}
