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
     * How long a game clock is still worth printing.
     *
     * 🚨 The poll floor is two minutes, on purpose — ESPN's scoreboard is
     * public and unauthenticated, which is the reason to be gentle with it, not
     * a reason to hammer it. So a clock here is at best two minutes old, and
     * "2:41 to play" that is three minutes stale is simply a wrong number
     * printed in a confident font.
     *
     * The PERIOD does not have this problem: a quarter lasts fifteen minutes,
     * so it is still true long after the clock beside it stopped being. Past
     * this window the clock is dropped and the period carries the line alone.
     */
    public const CLOCK_FRESH_FOR = 180;

    /**
     * The row, turned into what a scoreboard actually shows.
     *
     * 🚨 Decided HERE, not in the template. Whether a clock is still true,
     * which crest suits which theme, and what the status line should say are
     * all judgements, and a template language is for choosing what to show
     * rather than for working out what a thing means. It is also what lets the
     * page and the refresh endpoint answer identically — they call this.
     *
     * @param array<string, mixed> $game
     * @return array<string, mixed>
     */
    public function shape(array $game, ?int $now = null): array
    {
        $now ??= time();

        $status = (string) ($game['status'] ?? '');
        $threadState = (string) ($game['state'] ?? '');
        $hasScore = $game['home_score'] !== null && $game['away_score'] !== null;

        $state = match (true) {
            $status === 'finished' && $hasScore => 'final',
            $threadState === 'live' || $status === 'in_progress' => 'live',
            default => 'scheduled',
        };

        $period = (int) ($game['period'] ?? 0);
        $clockAt = (int) ($game['clock_at'] ?? 0);
        $fresh = $clockAt > 0 && ($now - $clockAt) <= self::CLOCK_FRESH_FOR;
        $clock = trim((string) ($game['clock'] ?? ''));

        $possession = $state === 'live' && $fresh
            && in_array((string) ($game['possession'] ?? ''), ['home', 'away'], true)
                ? (string) $game['possession']
                : null;

        /*
         * Marked on the SIDE, because that is where the football is drawn and
         * the strip renders the two sides through one loop — a template asking
         * "is this the home one?" halfway down a loop is a template doing
         * arithmetic.
         */
        $home = $this->side($game, 'home');
        $away = $this->side($game, 'away');
        $home['has_ball'] = $possession === 'home';
        $away['has_ball'] = $possession === 'away';

        return [
            'id' => (int) $game['id'],
            'state' => $state,

            /*
             * ESPN's own wording where there is any — "2nd Quarter", "Halftime",
             * "End of 3rd". Better than anything built from a number here,
             * because it already knows what a period means in a game that has
             * gone to overtime.
             */
            'period_line' => $this->periodLine($game, $period, $state),
            'clock' => $state === 'live' && $fresh && $clock !== '' ? $clock : null,

            /*
             * 🚨 Possession only while it is fresh, and only during play.
             *
             * A football sitting beside a team is a strong claim — it says
             * "they have the ball, now". Three minutes after the fact that is
             * simply wrong, and wrong in the most visible way on the board, so
             * it goes when the clock goes rather than lingering as decoration.
             */
            'possession' => $possession,
            'down' => $state === 'live' && $fresh ? (trim((string) ($game['down_distance'] ?? '')) ?: null) : null,
            'red_zone' => $state === 'live' && $fresh && !empty($game['red_zone']),
            'clock_stale' => $state === 'live' && $clockAt > 0 && !$fresh,
            'kickoff' => \Convoro\Engine\Support\Presence::format('D j M, g:ia T', (int) $game['match_at']),
            'home' => $home,
            'away' => $away,
            'topic_id' => $game['topic_id'] === null ? null : (int) $game['topic_id'],
            'topic_slug' => $game['topic_slug'] ?? null,
        ];
    }

    /** @param array<string, mixed> $game */
    private function periodLine(array $game, int $period, string $state): string
    {
        if ($state === 'final') {
            // Overtime is worth saying; a regulation finish is just "Final".
            return $period > 4 ? 'Final / OT' : 'Final';
        }

        if ($state !== 'live') {
            return '';
        }

        $detail = trim((string) ($game['clock_detail'] ?? ''));

        /*
         * 🚨 ESPN's short detail already carries the clock — "5:44 - 2nd".
         * The clock has its own place on the strip, so printing this whole
         * would say 5:44 twice, and the second one would go stale while the
         * first was being dropped for exactly that reason.
         *
         * Everything after the dash is the part worth keeping. Where there is
         * no dash the whole thing is the period and says something a number
         * cannot: "Halftime", "End of 3rd".
         */
        if ($detail !== '') {
            $dash = strpos($detail, ' - ');

            return $dash === false ? $detail : trim(substr($detail, $dash + 3));
        }

        return $period > 0 ? self::ordinal($period) : '';
    }

    private static function ordinal(int $period): string
    {
        return match (true) {
            $period === 1 => '1st',
            $period === 2 => '2nd',
            $period === 3 => '3rd',
            $period === 4 => '4th',
            // 5 is the first overtime, 6 the second, and so on.
            default => 'OT' . ($period > 5 ? (string) ($period - 4) : ''),
        };
    }

    /**
     * @param array<string, mixed> $game
     * @return array<string, mixed>
     */
    private function side(array $game, string $which): array
    {
        return [
            'name' => (string) ($game[$which . '_name'] ?? ''),
            'abbr' => (string) ($game[$which . '_abbr'] ?? ''),
            'logo' => (string) ($game[$which . '_logo'] ?? ''),
            'logo_dark' => (string) ($game[$which . '_logo_dark'] ?? ''),
            'score' => $game[$which . '_score'] === null ? null : (int) $game[$which . '_score'],
        ];
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
                    e.`period`, e.`clock`, e.`clock_detail`, e.`clock_at`,
                    e.`possession`, e.`down_distance`, e.`red_zone`,
                    h.`name` AS home_name, h.`abbreviation` AS home_abbr,
                    h.`logo_path` AS home_logo, h.`logo_dark_path` AS home_logo_dark,
                    a.`name` AS away_name, a.`abbreviation` AS away_abbr,
                    a.`logo_path` AS away_logo, a.`logo_dark_path` AS away_logo_dark,
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
