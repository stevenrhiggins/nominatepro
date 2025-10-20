<?php
declare(strict_types=1);

namespace App\Services\Awards;

/**
 * JudgesOptionsService
 *
 * Encapsulates logic for applying "judges options" from award settings.
 * Keeps business rules for assigning/removing judges or toggling
 * judge-related fields out of controllers.
 */
final class JudgesOptionsService
{
    /**
     * Apply judge-related options to the award entity/mapper.
     *
     * @param array<string,mixed> $post   Incoming POST data from the settings form.
     * @param object              $award  Mapper/entity instance to mutate (e.g., $award->blind_judging).
     */
    public function handleJudgesOptions(array $post, object $award): void
    {
        // Example: toggle blind judging
        if (array_key_exists('permit_blind_judging', $post)) {
            $award->permit_blind_judging = ($post['permit_blind_judging'] === 'on') ? 1 : 0;
        }

        // Example: assign judge instructions
        if (array_key_exists('judge_instructions', $post)) {
            $award->judge_instructions = trim((string) $post['judge_instructions']);
        }

        // Add more judge-related option handling here as needed…
    }
}
