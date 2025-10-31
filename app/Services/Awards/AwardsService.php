<?php

declare(strict_types=1);

namespace App\Services\Awards;

use App\Contracts\AwardRepositoryInterface; // your repo interface
use DB\SQL\Mapper;                           // F3 Mapper type-hint (optional but nice)
use Base;

final class AwardsService
{
    public function __construct(
        private AwardRepositoryInterface $awards,
        private Base $f3,
    ) {}

    // ------------------------ Counts ------------------------

    public function countAllByCp(string $cpSlug): int
    {
        return $this->awards->countByCpSlug($cpSlug);
    }

    public function countActiveByCp(string $cpSlug): int
    {
        return $this->awards->countActiveByCpSlugQuery($cpSlug);
    }

    public function countActiveByCpSlug(string $cpSlug): int
    {
        return $this->awards->countActiveByCpSlugQuery($cpSlug);
    }

    public function countNominationsByStatus(string $awardSlug, string $status): int
    {
        return $this->awards->countNominationsByStatus($awardSlug, $status);
    }

    public function countQuestionsByType(string $awardSlug, string $type): int
    {
        return $this->awards->countQuestionsByType($awardSlug, $type);
    }

    public function countSectionsByAwardSlug(string $awardSlug): int
    {
        return $this->awards->countSectionsByAwardSlug($awardSlug);
    }

    // ------------------------ Fetches / Lists ------------------------

    /** List all awards (executed rows) */
    public function listAll(string $cpSlug): array
    {
        $rows = $this->awards->fetchAllAwardsQuery($cpSlug);
        return is_array($rows) ? $rows : [];
    }

    /** Gets all columns in all awards for a control panel */
    public function listAllAwards(string $cpSlug): array
    {
        $rows = $this->awards->fetchAwardsQuery($cpSlug);
        return is_array($rows) ? $rows : [];
    }

    /** List soft-deleted awards (executed rows) */
    public function listDeleted(string $cpSlug): array
    {
        $rows = $this->awards->fetchDeletedRecordsQuery($cpSlug);
        return is_array($rows) ? $rows : [];
    }

    public function fetchActiveAwardsByCpSlug(string $cpSlug): array
    {
        return $this->awards->fetchActiveAwardsByCpSlug($cpSlug);
    }

    public function fetchAwardByAwardSlug(string $slug): array
    {
        return $this->awards->fetchAwardByAwardSlug($slug);
    }

    public function fetchAwardByAwardSlugMapperVersion(string $slug): Mapper
    {
        return $this->awards->fetchAwardByAwardSlugMapperVersionQuery($slug);
    }

    public function fetchAwardLastEdited(string $awardSlug): ?string
    {
        return $this->awards->fetchAwardLastEdited($awardSlug);
    }

    public function fetchNominationsByStatus(string $awardSlug, string $status): array
    {
        return $this->awards->fetchNominationsByStatus($awardSlug, $status);
    }

    public function fetchNominatorNomineeContactInformation(string $awardSlug, string $type): array
    {
        return $this->awards->fetchNominatorNomineeContactInformation($awardSlug, $type);
    }

    public function fetchSponsorData(string $awardSlug): array
    {
        return $this->awards->fetchSponsorLogoQuery($awardSlug);
    }

    //TODO REMOVE THE FOLLOWING 2 FUNCTIONS
//    public function countQuestionsByType(string $awardSlug, string $type): int
//    {
//        return $this->awards->countQuestionsByType($awardSlug, $type);
//    }
//
//    public function countSectionsByCpSlug(string $cpSlug): int
//    {
//        return $this->awards->countSectionsByCpSlug($cpSlug);
//    }

    public function switchNomination(string $awardSlug, string $action): void
    {
        $today = date('Y-m-d');

        if ($action === 'on') {
            $end = date('Y-m-d', strtotime('+1 month'));
        } else { // off
            $today = date('Y-m-d', strtotime('+1 day'));
            $end = $today;
        }

        $this->awards->updateNominationDates($awardSlug, $today, $end);
    }


    // ------------------------ Metrics ------------------------

    public function getMetricsForAward(string $awardSlug): array
    {
        $metrics = [
            'number_nominator_questions' => $this->countQuestionsByType($awardSlug, 'nominator'),
            'number_nominee_questions'   => $this->countQuestionsByType($awardSlug, 'nominee'),
            'number_sections'            => $this->countSectionsByAwardSlug($awardSlug),
            'all_nominations'            => $this->countNominationsByStatus($awardSlug, 'all'),
            'ongoing_nominations'        => $this->countNominationsByStatus($awardSlug, 'ongoing'),
            'completed_nominations'      => $this->countNominationsByStatus($awardSlug, 'completed'),
            'demo_nominations'           => $this->countNominationsByStatus($awardSlug, 'demo'),
            'all_awards'                 => $this->countAllByCp($this->f3->get('SESSION.cp_slug')),
        ];

        $today = date('Y-m-d');
        $dates = $this->awards->fetchNominationDatesBySlug($awardSlug);
        $metrics['nomination_is_active'] = false;

        if ($dates && !empty($dates['nomination_start_date']) && !empty($dates['nomination_end_date'])) {
            $metrics['nomination_is_active'] =
                ($dates['nomination_start_date'] <= $today && $dates['nomination_end_date'] >= $today);
        }
        return $metrics;
    }

    // ------------------------ Persistence ------------------------

    /** Pass-throughs used elsewhere in your service */
    public function createAward(): array
    {
        return $this->awards->createAward();
    }

    public function createUpdateNominatorNomineeContact(string $awardSlug, string $type, array $data): array
    {
        return $this->awards->createUpdateNominatorNomineeContactQuery($awardSlug, $type, $data);
    }

    public function awardNameExists(string $cpSlug, string $awardName): bool
    {
        return $this->awards->awardNameExistsQuery($cpSlug, $awardName);
    }

    public function updateSettings(string $slug, array $post, array $files, string $today): bool
    {
        if ($slug === '') return false;

        // 1) Whitelist
        $allowed = [
            'access_token',
            'use_access_text',
            'use_access_token',
            'award_category',
            'award_name',
            'award_description',
            'award_mission',
            'award_welcome',
            'award_prize',
            'award_questionnaire_message_nominators',
            'award_questionnaire_message_nominees',
            'award_success_message',
            'award_nominee_requirements',
            'award_judging',
            'nomination_type',
            'permit_file_upload',
            'nominator_maximum_number_files',
            'nominator_file_instructions',
            'nominee_maximum_number_files',
            'nominee_file_instructions',
            'nomination_start_date',
            'nomination_end_date',
            'require_judging',
            'judging_start_date',
            'judging_end_date',
            'judges',
            'judges_password',
            'judges_invitation',
            'judges_invitation_subject',
            'judges_contact',
            'judges_contact_email',
            'permit_blind_judging',
            'permit_vote_changing',
            'edit_votes',
            'numeric_questions_instructions',
            'open_questions_instructions',
            'evaluation_scale',
            'evaluation_type',
            'nominator_title',
            'nominee_title',
            'nominator_summary_email',
            'nominator_self_nominated_summary_email',
            'nominee_summary_email',
            'nominee_nominated_email',
            'nominee_nominated_again_email',
            'nominee_required_email',
            'nominate_someone',
            'self_nominate',
            'require_nominee',
            'notify_nominee',
            'require_third_party',
            'anonymous_third_party',
            'nominee_view_nominator',
            'coming_from',
            'contact',
            'contact_email',
            'use_contact_information',
            'disabled_by_admin',
            'sponsor_name',
            'sponsor_logo',
            'sponsor_link',
            'sponsor_acknowledgement',
            'date_edited',
        ];

        $safe = array_intersect_key($post, array_flip($allowed));
        $safe['date_edited'] = $today;

        // 2) Handle uploads
        foreach (['award_logo', 'hero_image'] as $field) {
            if (!empty($files[$field]['name']) && $files[$field]['error'] === UPLOAD_ERR_OK) {
                $targetDir = 'ui/images/logos/awards/'. $slug . '/';
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0775, true);
                }
                $filename = basename($files[$field]['name']);
                $dest = $targetDir . $filename;
                if (!@move_uploaded_file($files[$field]['tmp_name'], $dest)) {
                    // You may want to log but not fail the whole update
                } else {
                    $safe[$field] = $filename;
                    $this->f3->set('SESSION.'.$field, $filename);
                }
            }
        }

        // 3) Persist (repo loads by slug → copyFrom → save)
        return $this->awards->updateBySlug($slug, $safe, $allowed);
    }

    public function save(Mapper $award): void
    {
        $this->awards->save($award);
    }

    public function validateAccessToken($awardSlug, $submitted): void
    {
        $this->awards->validateAccessTokenQuery($awardSlug, $submitted);
    }

    public function validateRegisrationToken($awardSlug, $token, $email): void
    {
        $this->awards->validateRegistrationTokenQuery($awardSlug, $token, $email);
    }
}
