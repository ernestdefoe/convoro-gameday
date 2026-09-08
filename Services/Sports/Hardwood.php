<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services\Sports;

/**
 * Basketball — the NBA and college.
 *
 * 🚨 The statistic names are ESPN's own, read off a live NBA summary rather
 * than guessed: `fieldGoalsMade-fieldGoalsAttempted`, `threePointFieldGoalPct`,
 * `totalRebounds`, `turnovers`, `pointsInPaint`. A key invented here would
 * simply never match, and a comparison that silently prints nothing is the
 * worst kind of wrong.
 *
 * 🚨 Basketball is the sport where the SCORE is least informative. Every game
 * ends near a hundred points and a fifteen-point win is comfortable, so the
 * margins below are nothing like gridiron's — twenty-eight points is a rout in
 * football and a normal Tuesday here is closer to twenty.
 */
class Hardwood extends Sport
{
    public function key(): string
    {
        return 'hardwood';
    }

    public function name(): string
    {
        return 'Basketball';
    }

    public function comparison(): array
    {
        return [
            'fieldGoalsMade-fieldGoalsAttempted' => 'Field goals',
            'fieldGoalPct' => 'FG%',
            'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => 'Three-pointers',
            'threePointFieldGoalPct' => '3P%',
            'freeThrowsMade-freeThrowsAttempted' => 'Free throws',
            'totalRebounds' => 'Rebounds',
            'assists' => 'Assists',
            'steals' => 'Steals',
            'blocks' => 'Blocks',
            'turnovers' => 'Turnovers',
            'pointsInPaint' => 'Points in the paint',
        ];
    }

    public function narrative(array $sides, string $home, string $away): array
    {
        $said = [];

        /*
         * 🚨 Shooting, first and usually only. Basketball games are decided by
         * whether the ball went in far more often than by anything else on the
         * sheet, and a recap that led with rebounds would be describing the
         * consolation rather than the game.
         */
        $homePct = $this->decimal($sides['home']['stats']['fieldGoalPct'] ?? null);
        $awayPct = $this->decimal($sides['away']['stats']['fieldGoalPct'] ?? null);

        if ($homePct !== null && $awayPct !== null) {
            $gap = abs($homePct - $awayPct);

            /*
             * Six points of field-goal percentage is roughly three made shots
             * over a full game — the point at which one side genuinely shot
             * better rather than got slightly luckier.
             */
            if ($gap >= 6.0) {
                $better = $homePct > $awayPct ? $home : $away;
                $worse = $homePct > $awayPct ? $away : $home;

                $said[] = sprintf(
                    '%s shot %s%% to %s\'s %s%%.',
                    $better,
                    $this->trimPct(max($homePct, $awayPct)),
                    $worse,
                    $this->trimPct(min($homePct, $awayPct)),
                );
            }
        }

        /*
         * 🚨 The three-point line, only when somebody actually made a
         * difference at it. Both teams take thirty of them now; the fact worth
         * saying is a gap, not a count.
         */
        $homeThrees = $this->made($sides['home']['stats']['threePointFieldGoalsMade-threePointFieldGoalsAttempted'] ?? null);
        $awayThrees = $this->made($sides['away']['stats']['threePointFieldGoalsMade-threePointFieldGoalsAttempted'] ?? null);

        if ($homeThrees !== null && $awayThrees !== null && abs($homeThrees - $awayThrees) >= 5) {
            $more = $homeThrees > $awayThrees ? $home : $away;

            $said[] = sprintf(
                '%s made %d threes to %d.',
                $more,
                max($homeThrees, $awayThrees),
                min($homeThrees, $awayThrees),
            );
        }

        // Turnovers, on the same rule as everywhere else: only a real gap.
        $homeTo = $this->number($sides['home']['stats']['turnovers'] ?? null);
        $awayTo = $this->number($sides['away']['stats']['turnovers'] ?? null);

        if ($homeTo !== null && $awayTo !== null && abs($homeTo - $awayTo) >= 6) {
            $sloppier = $homeTo > $awayTo ? $home : $away;

            $said[] = sprintf(
                '%s gave it away %s to %s\'s %d.',
                $sloppier,
                $this->times(max($homeTo, $awayTo)),
                $sloppier === $home ? $away : $home,
                min($homeTo, $awayTo),
            );
        }

        return $said;
    }

    public function leaderCategories(): array
    {
        /*
         * 🚨 ESPN's basketball box score is ONE unnamed group of players with a
         * single row each — there is no passing/rushing/receiving split to lead
         * separately. So there is one category, and who "led" it is who scored
         * most, which is also how anybody talking about the game would pick.
         */
        return ['general' => 'PTS'];
    }

    public function playerLine(string $category, array $stats): string
    {
        $points = $this->number($stats['PTS'] ?? null);

        if ($points === null) {
            return '';
        }

        $parts = [$points . ' points'];

        $rebounds = $this->number($stats['REB'] ?? null);
        $assists = $this->number($stats['AST'] ?? null);

        /*
         * 🚨 Rebounds and assists only when they were worth mentioning. Every
         * line would otherwise read "22 points, 3 rebounds, 2 assists", which
         * is three facts where one was interesting.
         */
        if ($rebounds !== null && $rebounds >= 8) {
            $parts[] = $rebounds . ' rebounds';
        }

        if ($assists !== null && $assists >= 6) {
            $parts[] = $assists . ' assists';
        }

        $line = implode(', ', $parts);

        // The one thing basketball has that nothing else does.
        if (($rebounds ?? 0) >= 10 && ($assists ?? 0) >= 10 && $points >= 10) {
            $line .= ' — a triple-double';
        }

        return $line;
    }

    public function formatStat(string $key, string $value): string
    {
        return str_ends_with($key, 'Pct') ? $this->trimPct((float) $value) . '%' : $value;
    }

    protected function margin(string $winner, int $margin): string
    {
        return match (true) {
            $margin <= 3 => sprintf('%s got out with it, by %d.', $winner, $margin),
            $margin <= 8 => sprintf('%s took it by %d.', $winner, $margin),
            $margin <= 15 => sprintf('%s won it by %d.', $winner, $margin),
            $margin <= 25 => sprintf('%s had it comfortably.', $winner),
            default => sprintf('%s were never troubled.', $winner),
        };
    }

    protected function drawnGame(): string
    {
        /*
         * 🚨 Unreachable in practice and deliberately not deleted. Basketball
         * plays overtime until somebody wins, so a level score means the feed
         * gave us a game that was abandoned or that we read too early — and a
         * recap that said "it finished level" about a suspended game would be
         * stating something nobody claimed.
         */
        return 'The game did not finish level in the ordinary way — check the result.';
    }

    /** `13-40` → 13. The made half of a made-attempted pair. */
    protected function made(mixed $value): ?int
    {
        if (!is_string($value) || !str_contains($value, '-')) {
            return null;
        }

        return $this->number(explode('-', $value, 2)[0]);
    }

    /** 46.0 → "46", 46.5 → "46.5". A whole percentage does not want a `.0`. */
    protected function trimPct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
