<?php
declare(strict_types=1);

namespace App\Contracts;

use DB\SQL\Mapper;

/**
 * Contract for awards data access.
 * NOTE: Service code calls save() and createAward(), so they must be in the interface.
 */
interface AwardRepositoryInterface
{
    // ------------------------ Counts ------------------------

    /** Total number of awards for a given cp_slug. */
    public function countByCpSlug(string $cpSlug): int;

    /** Number of active (non-deleted, within dates) awards for a given cp_slug. */
    public function countActiveByCpSlugQuery(string $cpSlug): int;

    /** Count questions by type for an award. */
    public function countQuestionsByType(string $awardSlug, string $type): int;

    /** Count sections for a cp_slug. */
    public function countSectionsByAwardSlug(string $awardSlug): int;

    /** Count ongoing nominations by status. */
    public function countNominationsByStatus(string $awardSlug, string $status): int;

    public function createUpdateNominatorNomineeContactQuery(string $awardSlug, string $type, array $data): ?int;

    // ------------------------ Fetches ------------------------

    /** All awards for a control panel (executed result rows). */
    public function fetchAllAwardsQuery(string $cpSlug): array;

    /** Gets all columns for control panel awards. */
    public function fetchAwardsQuery(string $cpSlug): array;

    /** Soft-deleted records for a control panel (executed result rows). */
    public function fetchDeletedRecordsQuery(string $cpSlug): array;

    /** Fetch completed nominations for a specific award. */
    public function fetchNominationsByStatus(string $awardSlug, string $status): array;

    /** Count of submitted nominations for an award (executed result rows with a count). */
    public function fetchSubmittedNominationsCount(string $awardSlug): array;

    /** List active awards for a given cp_slug. */
    public function fetchActiveAwardsByCpSlug(string $cpSlug): array;

    /** Rows for a specific award by slug (executed result rows). */
    public function fetchAwardByAwardSlug(string $awardSlug): array;

    /** Rows for a specific award by slug (executed result rows). */
    public function fetchAwardByAwardSlugMapperVersionQuery(string $awardSlug): Mapper;

    /** Fetch last edited timestamp for a specific award. */
    public function fetchAwardLastEdited(string $awardSlug): ?string;

    /** Fetch nomination start/end dates for a specific award. */
    public function fetchNominationDatesBySlug(string $awardSlug): ?array;

    /** Fetch nominator/nominee contact information for a specific award. */
    public function fetchNominatorNomineeContactInformation(string $awardSlug, string $type): ?array;

    public function fetchSponsorLogoQuery(string $awardSlug): ?array;

    public function updateNominationDates(string $awardSlug, string $startDate, string $endDate): void;

    public function validateAccessTokenQuery(string $awardSlug, string $accessToken): void;
    public function validateRegistrationTokenQuery(string $awardSlug, string $code, string $email): bool;

    // ------------------------ Persistence ------------------------

    /** Persist a Mapper row. Required by AwardsService->save($award). */
    public function save(Mapper $award): void;

    /** Create a new award row with safe defaults (used where your service calls createAward()). */
    public function createAward(): ?array;

    /** Check if an award name exists for a given control panel. */
    public function awardNameExistsQuery(string $cpSlug, string $awardName): bool;
}
