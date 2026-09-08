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

    /**
     * Whether Picks records which league a season is.
     *
     * 🚨 Probed rather than assumed, because these are two extensions on two
     * release lines. Picks gained `picks_seasons.league` after Game Day was
     * written, and a board running a newer Game Day against an older Picks is
     * an ordinary situation — not one that should produce an unknown-column
     * error on a job that runs every minute, taking every thread with it.
     *
     * Cached for the process: the answer cannot change inside one request, and
     * this is asked on every pass of the loop.
     */
    private ?bool $leagueColumn = null;

    private function hasLeagueColumn(): bool
    {
        if ($this->leagueColumn !== null) {
            return $this->leagueColumn;
        }

        try {
            $table = $this->db->prefixed('picks_seasons');

            foreach ($this->db->select("SHOW COLUMNS FROM `{$table}`") as $row) {
                if (($row['Field'] ?? '') === 'league') {
                    return $this->leagueColumn = true;
                }
            }
        } catch (\Throwable) {
            // Picks is not installed at all. `available()` says so properly.
        }

        return $this->leagueColumn = false;
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
        /*
         * 🚨 A SCORE IS NOT A RESULT. The feed has to say the game is over.
         *
         * This asked only for two scores and a confirmation, which reads "we
         * have heard a score" as "the game has finished" — and those are the
         * same sentence only if a score never arrives mid-game. Scores never
         * did arrive, because every fetch had been refused with a 403 since the
         * day this was written, so the condition looked correct for a whole
         * off-season.
         *
         * The first live score this site ever recorded was North Carolina 10,
         * TCU 10 in the second quarter. Seconds later every gameday thread with
         * a score was declared over, the threads were resolved, and a recap
         * went out announcing a tie — of a game still being played, at a score
         * football does not even end on.
         *
         * `finished` is the only status that means finished.
         */
        return $this->select(
            "AND t.`state` IN ('open', 'live')
             AND e.`status` = 'finished'
             AND e.`confirmed_at` > 0
             AND e.`home_score` IS NOT NULL AND e.`away_score` IS NOT NULL
             ORDER BY e.`match_at` ASC
             LIMIT " . max(1, min(50, $limit))
        );
    }

    /**
     * Games being played whose thread is meant to be live.
     *
     * The mirror of `dueToResolve()`: that one asks who has finished, this asks
     * who has not. Both lean on the game's own status rather than on anything
     * about the thread, because the game is what decides.
     *
     * @return list<array<string, mixed>>
     */
    public function stillPlaying(int $limit = 20): array
    {
        return $this->select(
            "AND t.`state` = 'live'
             AND e.`status` = 'in_progress'
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
        $weeks = $this->db->prefixed('picks_weeks');
        $seasons = $this->db->prefixed('picks_seasons');

        $league = $this->hasLeagueColumn() ? 's.`league` AS league' : "'' AS league";
        $leagueJoin = $this->hasLeagueColumn()
            ? "LEFT JOIN `{$weeks}` w ON w.`id` = e.`week_id`
               LEFT JOIN `{$seasons}` s ON s.`id` = w.`season_id`"
            : '';

        /*
         * 🚨 The league is JOINED here rather than looked up per game. A recap
         * has to be written in its own sport's words — a board following the
         * NFL and the Premier League has both on the same Sunday — and asking
         * for the season one game at a time is two queries per thread on a
         * loop that already runs every minute.
         *
         * 🚨 LEFT joins, and a fallback where the row is missing. Picks' league
         * column arrived after this did, and a game whose season predates it,
         * or whose week was deleted, is a game that should still get a thread.
         */
        return $this->db->select(
            "SELECT e.`id`, e.`match_at`, e.`status`, e.`neutral_site`,
                    e.`home_score`, e.`away_score`, e.`confirmed_at`,
                    h.`name` AS home_name, h.`abbreviation` AS home_abbr, h.`forum_id` AS home_forum_id,
                    a.`name` AS away_name, a.`abbreviation` AS away_abbr, a.`forum_id` AS away_forum_id,
                    t.`id` AS thread_id, t.`topic_id`, t.`state` AS thread_state, t.`recap_post_id`,
                    {$league}
               FROM `{$events}` e
               INNER JOIN `{$teams}` h ON h.`id` = e.`home_team_id`
               INNER JOIN `{$teams}` a ON a.`id` = e.`away_team_id`
               LEFT JOIN `{$threads}` t ON t.`event_id` = e.`id`
               {$leagueJoin}
              WHERE 1 = 1 " . $where,
            $bindings
        );
    }
}
