<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services;

use Convoro\Engine\Database\Connection;

/**
 * The box score Picks has synced, read.
 *
 * 🚨 Read-only, and deliberately the only place here that knows Picks' column
 * names — the same rule `Games` keeps, for the same reason. If Picks ever
 * renames something, this file is the blast radius rather than a service, a
 * recap and a template.
 *
 * 🚨 Nothing here reaches CollegeFootballData. Picks owns the provider, the API
 * key and the monthly call budget; this reads what it left behind. A second
 * place that could make that call is a second place the budget is not enforced.
 */
final class BoxScore
{
    public function __construct(private Connection $db)
    {
    }

    /** Whether Picks is new enough to be keeping box scores at all. */
    public function available(): bool
    {
        try {
            $this->db->select('SELECT 1 FROM `' . $this->db->prefixed('picks_box_scores') . '` LIMIT 1');

            return true;
        } catch (\Throwable) {
            /*
             * An older Picks has no such table, and that is a perfectly good
             * state to be in — the recap simply says the score, which is what
             * it said before any of this existed.
             */
            return false;
        }
    }

    /**
     * The normalised box score for a game, or null when there is none yet.
     *
     * @return array<string, mixed>|null
     */
    public function forEvent(int $eventId): ?array
    {
        if (!$this->available()) {
            return null;
        }

        $row = $this->db->table('picks_box_scores')->where('event_id', $eventId)->first();

        if ($row === null) {
            return null;
        }

        $document = json_decode((string) $row['payload'], true);

        return is_array($document) ? $document : null;
    }
}
