<?php
declare(strict_types=1);

namespace App\Services\Awards;

/**
 * NominationSettingsService
 *
 * Encapsulates business logic for nominator/nominee-related settings
 * on an award. Keeps form-handling logic out of controllers.
 */
final class NominationSettingsService
{
    /**
     * Apply nominator/nominee related settings to the award entity/mapper.
     *
     * @param array<string,mixed> $post   Incoming POST data from the settings form.
     * @param object              $award  Mapper/entity instance to mutate.
     */
    public function handleNominatorNomineeSettings(array $post, object $award): void
    {
        // Example: self-nomination toggle
        if (array_key_exists('self_nominate', $post)) {
            $award->self_nominate = ($post['self_nominate'] === 'on') ? 1 : 0;
        }

        // Example: maximum number of nominations
        if (array_key_exists('max_nominations', $post)) {
            $award->max_nominations = (int) $post['max_nominations'];
        }

        // Example: require nominee contact info
        if (array_key_exists('require_nominee_contact', $post)) {
            $award->require_nominee_contact = ($post['require_nominee_contact'] === 'on') ? 1 : 0;
        }

        // Example: require nominator statement
        if (array_key_exists('require_nominator_statement', $post)) {
            $award->require_nominator_statement = ($post['require_nominator_statement'] === 'on') ? 1 : 0;
        }

        // Add more nomination-specific settings as your schema requires…
    }
}
