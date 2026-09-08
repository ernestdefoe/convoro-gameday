<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services\Sports;

/**
 * Ice hockey.
 *
 * 🚨 Statistic names read off a live NHL summary: `shotsTotal`, `powerPlayPct`,
 * `faceoffPercent`, `hits`, `blockedShots`, `giveaways`, `penaltyMinutes`.
 *
 * 🚨 Hockey's own trap is the SHOT COUNT. A team can be out-shot 40 to 20 and
 * win, and it happens often enough that a recap treating shots the way gridiron
 * treats yardage would be wrong most nights. So the shots sentence is written
 * to survive disagreeing with the score, and the goaltender is named when the
 * numbers say he is the reason.
 */
class Ice extends Sport
{
    public function key(): string
    {
        return 'ice';
    }

    public function name(): string
    {
        return 'Ice hockey';
    }

    public function comparison(): array
    {
        return [
            'shotsTotal' => 'Shots',
            'powerPlayGoals' => 'Power-play goals',
            'powerPlayOpportunities' => 'Power plays',
            'faceoffPercent' => 'Faceoffs',
            'blockedShots' => 'Blocked shots',
            'hits' => 'Hits',
            'giveaways' => 'Giveaways',
            'takeaways' => 'Takeaways',
            'penaltyMinutes' => 'Penalty minutes',
        ];
    }

    public function narrative(array $sides, string $home, string $away): array
    {
        $said = [];

        $homeShots = $this->number($sides['home']['stats']['shotsTotal'] ?? null);
        $awayShots = $this->number($sides['away']['stats']['shotsTotal'] ?? null);

        if ($homeShots !== null && $awayShots !== null && abs($homeShots - $awayShots) >= 8) {
            $more = $homeShots > $awayShots ? $home : $away;
            $fewer = $homeShots > $awayShots ? $away : $home;

            $said[] = sprintf(
                '%s out-shot %s %d to %d.',
                $more,
                $fewer,
                max($homeShots, $awayShots),
                min($homeShots, $awayShots),
            );
        }

        /*
         * 🚨 The power play, only when one was converted. "0 for 3" is the
         * ordinary night and reporting it every game buries the game where
         * somebody actually scored two of them.
         */
        $homePp = $this->number($sides['home']['stats']['powerPlayGoals'] ?? null);
        $awayPp = $this->number($sides['away']['stats']['powerPlayGoals'] ?? null);

        if (($homePp ?? 0) + ($awayPp ?? 0) > 0) {
            /*
             * 🚨 Written as a whole sentence for each case rather than as
             * clauses joined with a comma. Building it up from parts produced
             * "Dallas Stars once." on the night only the away side converted —
             * the second clause was written to lean on the first one that was
             * never added.
             */
            $said[] = match (true) {
                ($homePp ?? 0) > 0 && ($awayPp ?? 0) > 0 => sprintf(
                    'Both scored on the power play — %s %s, %s %s.',
                    $home,
                    $this->times((int) $homePp),
                    $away,
                    $this->times((int) $awayPp),
                ),
                ($homePp ?? 0) > 0 => sprintf(
                    '%s scored %s on the power play.',
                    $home,
                    $this->times((int) $homePp),
                ),
                default => sprintf(
                    '%s scored %s on the power play.',
                    $away,
                    $this->times((int) $awayPp),
                ),
            };
        }

        return $said;
    }

    public function leaderCategories(): array
    {
        /*
         * 🚨 ESPN answers FOUR hockey groups and one of them is a decoy:
         * `forwards`, `defenses`, `goalies` and `skaters` — and `skaters`
         * carries a full set of column labels with NO athletes in it. Naming
         * from it would silently produce nothing, and naming from it as well as
         * the other two would print the same players twice.
         *
         * A skater is led by POINTS, which ESPN does not answer directly; goals
         * is the honest proxy and is what anybody would name anyway. A
         * defenceman who did nothing produces no line at all, so listing the
         * group costs a name only on the night one earned it.
         */
        return [
            'forwards' => 'G',
            'defenses' => 'G',
            'goalies' => 'SV',
        ];
    }

    public function playerLine(string $category, array $stats): string
    {
        if ($category === 'goalies') {
            $saves = $this->number($stats['SV'] ?? null);
            $against = $this->number($stats['GA'] ?? null);

            if ($saves === null) {
                return '';
            }

            /*
             * A shutout is the one goaltending line worth calling by its name.
             */
            if ($against === 0) {
                return $saves . ' saves for the shutout';
            }

            return $saves . ' saves';
        }

        $goals = $this->number($stats['G'] ?? null);
        $assists = $this->number($stats['A'] ?? null);

        if ($goals === null && $assists === null) {
            return '';
        }

        $parts = [];

        if (($goals ?? 0) > 0) {
            $parts[] = $goals === 1 ? 'a goal' : $this->word((int) $goals) . ' goals';
        }

        if (($assists ?? 0) > 0) {
            $parts[] = $assists === 1 ? 'an assist' : $this->word((int) $assists) . ' assists';
        }

        if ($parts === []) {
            return '';
        }

        $line = implode(' and ', $parts);

        // Three goals is a hat-trick and the whole rink knows it.
        if (($goals ?? 0) >= 3) {
            $line .= ' — a hat-trick';
        }

        return $line;
    }

    public function formatStat(string $key, string $value): string
    {
        return in_array($key, ['powerPlayPct', 'faceoffPercent'], true) ? $value . '%' : $value;
    }

    protected function margin(string $winner, int $margin): string
    {
        return match (true) {
            $margin === 1 => sprintf('%s took it by one.', $winner),
            $margin === 2 => sprintf('%s won it by two.', $winner),
            $margin <= 4 => sprintf('%s had it comfortably.', $winner),
            default => sprintf('%s were never troubled.', $winner),
        };
    }

    protected function drawnGame(): string
    {
        /*
         * 🚨 The NHL has not had a tie since 2005 — overtime and a shootout
         * settle every game. A level score means the feed handed us a game that
         * was not finished.
         */
        return 'It finished level, which means it did not finish.';
    }
}
