<?php
declare(strict_types=1);

namespace App\Services;

use Base;
use App\Contracts\QuestionRepositoryInterface;

class QuestionService
{
    public function __construct(
        private Base $f3,
        private QuestionRepositoryInterface $repo
    ) {}


    public function fetchSectionsAndQuestionCountBySection(string $awardSlug): array
    {
        return $this->repo->fetchSectionsAndQuestionCountBySection($awardSlug);
    }

    public function fetchSectionsByAwardSlug(string $awardSlug): array
    {
        return $this->repo->fetchSectionsByAwardSlug($awardSlug);
    }

    public function fetchSectionBySectionSlug(string $sectionSlug): array
    {
        return $this->repo->fetchSectionBySectionSlug($sectionSlug);
    }

    public function listByAward(string $awardSlug, ?string $type = null): array
    {
        return $this->repo->listByAward($awardSlug, $type);
    }

    public function listFollowUpsByQuestionSlug(string $questionSlug): array
    {
        return $this->repo->listFollowUpsByQuestionSlug($questionSlug);
    }

    public function countSections(string $awardSlug)
    {
        return $this->repo->countSectionsByAward($awardSlug);
    }

    public function countByAwardAndType(string $awardSlug, string $type): int
    {
        return $this->repo->countByAwardAndType($awardSlug, $type);
    }

    public function createQuestion (string $awardSlug, $data): int
    {
        return $this->repo->createQuestion($awardSlug, $data);
    }

    public function createFollowupQuestion (string $awardSlug, $data): int
    {
        return $this->repo->createFollowUp($awardSlug, $data);
    }

    public function createSection(string $awardSlug, $data): int
    {
        return $this->repo->createSection($awardSlug, $data);
    }

    public function updateQuestion (string $questionSlug, $data): int
    {
        return $this->repo->updateQuestion($questionSlug, $data);
    }

    public function updateSection (string $sectionSlug, $data): int
    {
        return $this->repo->updateSection($sectionSlug, $data);
    }

    public function deleteQuestion (string $questionSlug): int
    {
        return $this->repo->delete($questionSlug);
    }

    public function deleteSection (string $sectionSlug): int
    {
        return $this->repo->deleteSection($sectionSlug);
    }

    public function fetchQuestion(string $questionSlug): array
    {
        return $this->repo->findBySlug($questionSlug);
    }

    public function fetchFollowupQuestion(string $fuSlug): array
    {
        return $this->repo->fetchFollowUpQuestionBySlug($fuSlug);
    }

    public function updateFollowupQuestionBySlug(string $fuSlug, array $data): int
    {
        return $this->repo->updateFollowUpBySlug($fuSlug, $data);
    }

    public function deleteFollowupQuestionBySlug (string $fuSlug): int
    {
        return $this->repo->deleteFollowQuestionUpBySlug($fuSlug);
    }

    public function cloneQuestion(string $awardSlug, string $originalQuestionSlug, array $originalQuestionData): int
    {
        return $this->repo->clone($awardSlug, $originalQuestionSlug, $originalQuestionData);
    }

    // If you need to surface CRUD later, delegate similarly:
    // public function create(array $data): int { return $this->repo->create($data); }
    // etc.
}
