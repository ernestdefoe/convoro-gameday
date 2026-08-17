<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services;

use Convoro\Engine\Database\Connection;

/**
 * The one game worth showing right now.
 *
 * 🚨 Live first, then the next kickoff. A scoreboard that shows Saturday's fixture
 * while a game is being played is a scoreboard nobody looks at twice.
 */
final class Scoreboard
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @param  list<int>|null $readableForumIds null means nothing is restricted
     * @return array<string, mixed>|null
     */
    public function current(?array $readableForumIds): ?array
    {
        $game = $this->pick("t.`state` = 'live'") ?? $this->pick("t.`state` = 'open' AND e.`match_at` > " . time());

        if ($game === null) {
            return null;
        }

        /*
         * 🚨 The LINK is dropped when the reader cannot open the forum the thread
         * lives in — the score itself is public, but a link into a forum somebody
         * may not read is both a dead end and a disclosure that it exists.
         */
        if ($readableForumIds !== null && !in_array((int) $game['forum_id'], $readableForumIds, true)) {
            $game['topic_id'] = null;
            $game['topic_slug'] = null;
        }

        return $game;
    }

    /** @return array<string, mixed>|null */
    private function pick(string $where): ?array
    {
        $events = $this->db->prefixed('picks_events');
        $teams = $this->db->prefixed('picks_teams');
        $threads = $this->db->prefixed('gameday_threads');
        $topics = $this->db->prefixed('topics');

        $rows = $this->db->select(
            "SELECT e.`id`, e.`match_at`, e.`status`, e.`neutral_site`,
                    e.`home_score`, e.`away_score`,
                    h.`name` AS home_name, h.`abbreviation` AS home_abbr,
                    a.`name` AS away_name, a.`abbreviation` AS away_abbr,
                    t.`state`, t.`topic_id`, tp.`slug` AS topic_slug, tp.`forum_id`
               FROM `{$threads}` t
               INNER JOIN `{$events}` e ON e.`id` = t.`event_id`
               INNER JOIN `{$teams}` h ON h.`id` = e.`home_team_id`
               INNER JOIN `{$teams}` a ON a.`id` = e.`away_team_id`
               INNER JOIN `{$topics}` tp ON tp.`id` = t.`topic_id`
              WHERE {$where}
                AND tp.`deleted_at` IS NULL AND tp.`is_hidden` = 0
              ORDER BY e.`match_at` ASC
              LIMIT 1"
        );

        return $rows === [] ? null : $rows[0];
    }
}
