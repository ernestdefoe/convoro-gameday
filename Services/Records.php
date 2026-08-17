<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services;

use Convoro\Engine\Database\Connection;

/**
 * A member's pick record, for the flair beside their name.
 *
 * 🚨 Derived, never stored. Picks already keeps `picks_user_scores`; a second copy
 * would be a number that drifts from the standings it claims to show, and a rivalry
 * board notices that faster than anything else on the page.
 *
 * 🚨 Loaded ONCE PER REQUEST for everybody, not once per post. The hook fires for
 * every post on the page, and a query each would be forty on a busy thread — the
 * exact shape of slowness that gets blamed on "the forum being heavy".
 *
 * 🚨 That cache is right HERE and was wrong in {@see Settings}, which is worth
 * saying out loud because the two look identical. Settings is read every minute by
 * a worker that lives for hours, so caching it means ignoring an operator who
 * switched the feature off. This is read while rendering one page, by a container
 * that is thrown away at the end of it. Same code, opposite answer, and the
 * question that separates them is who reads it and for how long.
 */
final class Records
{
    /** @var array<int, array{correct: int, total: int}>|null */
    private ?array $loaded = null;

    public function __construct(private Connection $db)
    {
    }

    public function available(): bool
    {
        try {
            $this->db->select('SELECT 1 FROM `' . $this->db->prefixed('picks_user_scores') . '` LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * `12–4`, or null when there is nothing to show.
     *
     * 🚨 Null rather than `0–0` for somebody who has never picked. A zero record is
     * a claim about how they are doing; no record is the truth, and a board where
     * every lurker wears an 0–0 is a board that has made its own feature look broken.
     */
    public function forUser(int $userId): ?string
    {
        $record = $this->all()[$userId] ?? null;

        if ($record === null || $record['total'] < 1) {
            return null;
        }

        $wrong = max(0, $record['total'] - $record['correct']);

        return $record['correct'] . '–' . $wrong;
    }

    /**
     * Everybody's current-season record.
     *
     * @return array<int, array{correct: int, total: int}>
     */
    private function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $this->loaded = [];

        if (!$this->available()) {
            return $this->loaded;
        }

        $scores = $this->db->prefixed('picks_user_scores');

        /*
         * 🚨 Summed across the season's weeks rather than read from a single row.
         * `picks_user_scores` is per member PER WEEK, so taking one row would show
         * last Saturday's record as though it were the season's.
         */
        $rows = $this->db->select(
            "SELECT `user_id`,
                    SUM(`correct_picks`) AS correct,
                    SUM(`total_picks`) AS total
               FROM `{$scores}`
              WHERE `season_id` = (SELECT MAX(`season_id`) FROM `{$scores}`)
              GROUP BY `user_id`"
        );

        foreach ($rows as $row) {
            $this->loaded[(int) $row['user_id']] = [
                'correct' => (int) $row['correct'],
                'total' => (int) $row['total'],
            ];
        }

        return $this->loaded;
    }
}
