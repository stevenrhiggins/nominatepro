<?php
declare(strict_types=1);

namespace App\Contracts;

interface QuestionRepositoryInterface
{
    // Reads
    public function findBySlug(string $questionSlug): ?array;
    public function listByAward(string $awardSlug, ?string $type = null): array;
    public function fetchSectionsAndQuestionCountBySection(string $awardSlug): array;
    public function fetchSectionsByAwardSlug(string $awardSlug): array;
    public function fetchSectionBySectionSlug(string $sectionSlug): array;

    // Writes (questions)
    public function createQuestion(string $awardSlug, array $data): int;
    public function createSection(string $awardSlug, array $data): int;

    public function updateQuestion(string $questionSlug, array $data): int;
    public function updateSection(string $sectionSlug, array $data): int;

    public function deleteQuestion(string $questionSlug): int;
    public function reorder(string $awardSlug, array $slugToOrder): void;
    public function clone(string $awardSlug, string $originalQuestionSlug, array $originalQuestionData): int;

    // Follow-ups
    public function listFollowUpsByQuestionSlug(string $questionSlug): array;
    public function fetchFollowUpQuestionBySlug(string $fuSlug): ?array;
    public function createFollowUp(string $awardSlug, array $data): int;
    public function updateFollowUpBySlug(string $fuSlug, array $data): int;
    public function deleteFollowQuestionUpBySlug(string $fuSlug): int;

    // Utilities
    public function copyFromArray(array $questionRow, array $data): array;
    public function normalizeToggle(?string $val): string;
}
