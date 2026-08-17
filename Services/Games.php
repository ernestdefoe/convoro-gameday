<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services;

use Convoro\Engine\Database\Connection;

/**
 * The fixtures, read from the tables Picks keeps.
 *
 * 🚨 Read-only, and deliberately the only place that knows Picks' column names. If
 * Picks ever renames something, this file is the blast radius rather than four
 * services and a template.
 *
 * 🚨 Nothing here reaches CollegeFootballData. Picks syncs; this reads. See
 * convoro2/docs/design/2026-08-17-game-day.md.
 */
final class Games
{
    public function __construct(private Connection $db)
    {
    }

    /** Whether Picks is installed at all — this extension is useless without it. */
    public function available(): bool
    {
        try {
            $this->db->select('SELECT 1 FROM `' . $this->db->prefixed('picks_events') . '` LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Games kicking off within the window that have no thread yet.
     *
     * 🚨 Bounded at BOTH ends. The upper bound is the lead time, so threads open a
     * few hours out rather than for the whole season at once; the lower bound is
     * kickoff itself, because opening a thread for a game that has already finished
     * would post a season's worth of threads to a live board the moment this is
     * installed.
     *
     * @return list<array<string, mixed>>
     */
    public function dueToOpen(int $leadMinutes, int $limit = 20): array
    {
        $now = time();

        return $this->select(
            'AND e.`match_at` > ? AND e.`match_at` <= ? AND t.`id` IS NULL
             ORDER BY e.`match_at` ASC
             LIMIT ' . max(1, min(50, $limit)),
            [$now, $now + ($leadMinutes * 60)]
        );
    }

    /**
     * Threads whose game has kicked off and which are not live yet.
     *
     * @return list<array<string, mixed>>
     */
    public function dueToStart(int $limit = 20): array
    {
        return $this->select(
            "AND t.`state` = 'open' AND e.`match_at` <= ?
             ORDER BY e.`match_at` ASC
             LIMIT " . max(1, min(50, $limit)),
            [time()]
        );
    }

    /**
     * Threads whose game is over and has a confirmed score.
     *
     * 🚨 `confirmed_at`, not `status`. A game can be marked final by a feed before
     * the score settles, and a board that announces the wrong final score is worse
     * than one that is ten minutes late.
     *
     * @return list<array<string, mixed>>
     */
    public function dueToResolve(int $limit = 20): array
    {
        return $this->select(
            "AND t.`state` IN ('open', 'live') AND e.`confirmed_at` > 0
             AND e.`home_score` IS NOT NULL AND e.`away_score` IS NOT NULL
             ORDER BY e.`match_at` ASC
             LIMIT " . max(1, min(50, $limit))
        );
    }

    /** One game with its teams and its thread, if it has one. */
    public function find(int $eventId): ?array
    {
        $rows = $this->select('AND e.`id` = ? LIMIT 1', [$eventId]);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * @param  list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    private function select(string $where, array $bindings = []): array
    {
        $events = $this->db->prefixed('picks_events');
        $teams = $this->db->prefixed('picks_teams');
        $threads = $this->db->prefixed('gameday_threads');

        return $this->db->select(
            "SELECT e.`id`, e.`match_at`, e.`status`, e.`neutral_site`,
                    e.`home_score`, e.`away_score`, e.`confirmed_at`,
                    h.`name` AS home_name, h.`abbreviation` AS home_abbr, h.`forum_id` AS home_forum_id,
                    a.`name` AS away_name, a.`abbreviation` AS away_abbr, a.`forum_id` AS away_forum_id,
                    t.`id` AS thread_id, t.`topic_id`, t.`state` AS thread_state, t.`recap_post_id`
               FROM `{$events}` e
               INNER JOIN `{$teams}` h ON h.`id` = e.`home_team_id`
               INNER JOIN `{$teams}` a ON a.`id` = e.`away_team_id`
               LEFT JOIN `{$threads}` t ON t.`event_id` = e.`id`
              WHERE 1 = 1 " . $where,
            $bindings
        );
    }
}
