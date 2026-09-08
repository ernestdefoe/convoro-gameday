<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services\Sports;

/**
 * Baseball.
 *
 * 🚨 The statistic keys are PREFIXED, and that is not decoration. ESPN answers
 * a baseball team's statistics as GROUPS — batting, pitching, fielding — and
 * `hits` appears in all three meaning three different things: hits made, hits
 * allowed, and hits handled in the field. Flattening them onto bare names would
 * silently pick whichever group came last and print a pitcher's line as the
 * batting figure. The provider prefixes them; this reads them prefixed.
 *
 * 🚨 Baseball is the sport where the box score is the story. A one-run game and
 * a ten-run game are the same sport played differently, and the sentences below
 * are about how runs were manufactured rather than about a margin, because in
 * baseball the margin says the least of any sport here.
 */
class Diamond extends Sport
{
    public function key(): string
    {
        return 'diamond';
    }

    public function name(): string
    {
        return 'Baseball';
    }

    public function comparison(): array
    {
        return [
            'batting.hits' => 'Hits',
            'batting.runs' => 'Runs',
            'batting.homeRuns' => 'Home runs',
            'batting.RBIs' => 'RBI',
            'batting.walks' => 'Walks',
            'batting.strikeouts' => 'Strikeouts',
            'batting.runnersLeftOnBase' => 'Left on base',
            'pitching.strikeouts' => 'Strikeouts by pitchers',
            'pitching.earnedRuns' => 'Earned runs allowed',
            'fielding.errors' => 'Errors',
        ];
    }

    public function narrative(array $sides, string $home, string $away): array
    {
        $said = [];

        $homeHits = $this->number($sides['home']['stats']['batting.hits'] ?? null);
        $awayHits = $this->number($sides['away']['stats']['batting.hits'] ?? null);

        /*
         * 🚨 The hit count is only worth a sentence when it disagrees with the
         * score, and that disagreement is the most baseball thing there is: a
         * team out-hits another and loses, because hits are not runs. Where the
         * two agree, the score has already said it.
         */
        if ($homeHits !== null && $awayHits !== null && abs($homeHits - $awayHits) >= 3) {
            $moreHits = $homeHits > $awayHits ? $home : $away;
            $fewerHits = $homeHits > $awayHits ? $away : $home;

            $homeRuns = $this->number($sides['home']['stats']['batting.runs'] ?? null);
            $awayRuns = $this->number($sides['away']['stats']['batting.runs'] ?? null);
            $winnerByRuns = ($homeRuns !== null && $awayRuns !== null && $homeRuns !== $awayRuns)
                ? ($homeRuns > $awayRuns ? $home : $away)
                : null;

            if ($winnerByRuns !== null && $winnerByRuns === $fewerHits) {
                $said[] = sprintf(
                    '%s out-hit %s %d to %d and lost anyway.',
                    $moreHits,
                    $fewerHits,
                    max($homeHits, $awayHits),
                    min($homeHits, $awayHits),
                );
            } else {
                $said[] = sprintf(
                    '%s out-hit %s %d to %d.',
                    $moreHits,
                    $fewerHits,
                    max($homeHits, $awayHits),
                    min($homeHits, $awayHits),
                );
            }
        }

        // Home runs, when somebody hit one. A game with none needs no sentence.
        $homeHr = $this->number($sides['home']['stats']['batting.homeRuns'] ?? null);
        $awayHr = $this->number($sides['away']['stats']['batting.homeRuns'] ?? null);

        if (($homeHr ?? 0) + ($awayHr ?? 0) > 0) {
            $said[] = match (true) {
                ($homeHr ?? 0) > 0 && ($awayHr ?? 0) > 0 => sprintf(
                    'They went deep %s between them — %s %d, %s %d.',
                    $this->times((int) $homeHr + (int) $awayHr),
                    $home,
                    (int) $homeHr,
                    $away,
                    (int) $awayHr,
                ),
                ($homeHr ?? 0) > 0 => sprintf('%s went deep %s.', $home, $this->times((int) $homeHr)),
                default => sprintf('%s went deep %s.', $away, $this->times((int) $awayHr)),
            };
        }

        /*
         * 🚨 Errors, only when there were some. A clean game is the ordinary
         * case and a recap that reports "0 errors" every night has taught its
         * readers to skip the line by the second week.
         */
        $homeErr = $this->number($sides['home']['stats']['fielding.errors'] ?? null);
        $awayErr = $this->number($sides['away']['stats']['fielding.errors'] ?? null);

        if (($homeErr ?? 0) + ($awayErr ?? 0) >= 2) {
            $said[] = sprintf(
                'It was not clean — %s %s, %s %s in the field.',
                $home,
                $this->times((int) $homeErr),
                $away,
                $this->times((int) $awayErr),
            );
        }

        return $said;
    }

    public function leaderCategories(): array
    {
        /*
         * 🚨 ESPN's baseball player box score is two groups — batting and
         * pitching — and they want different figures, which is exactly what a
         * category is for. Who "led" the batting is who drove in the most runs
         * rather than who got the most hits, because that is the line anybody
         * would quote.
         */
        return [
            'batting' => 'RBI',
            'pitching' => 'K',
        ];
    }

    public function playerLine(string $category, array $stats): string
    {
        if ($category === 'pitching') {
            $innings = trim((string) ($stats['IP'] ?? ''));
            $strikeouts = $this->number($stats['K'] ?? null);
            $earned = $this->number($stats['ER'] ?? null);

            if ($innings === '') {
                return '';
            }

            $line = $innings . ' innings';

            if ($earned !== null) {
                $line .= $earned === 0 ? ', no earned runs' : ', ' . $earned . ' earned';
            }

            if ($strikeouts !== null && $strikeouts > 0) {
                $line .= ', ' . $strikeouts . ' struck out';
            }

            return $line;
        }

        // Batting. ESPN writes the day's line as `H-AB`, e.g. `2-4`.
        $line = trim((string) ($stats['H-AB'] ?? ''));

        if ($line === '') {
            return '';
        }

        $rbi = $this->number($stats['RBI'] ?? null);
        $homeRuns = $this->number($stats['HR'] ?? null);
        $hits = $this->number(explode('-', $line, 2)[0]);

        /*
         * 🚨 A hitless night with nothing driven in is not worth a name, and
         * this is where that is decided rather than where the leader is picked.
         * The leader is whoever drove in the most runs; in a 1–0 game that is
         * everybody, tied on nothing, and the first name in the order wins it.
         * "Ronald Acuna Jr. 0 for 3" was the result — a line that says a good
         * player had a quiet night, printed as though it were the highlight.
         */
        if (($hits ?? 0) === 0 && ($rbi ?? 0) === 0 && ($homeRuns ?? 0) === 0) {
            return '';
        }

        $said = $this->hitLine($line);

        if (($homeRuns ?? 0) > 0) {
            $said .= ', ' . ($homeRuns === 1 ? 'a home run' : $this->word((int) $homeRuns) . ' home runs');
        }

        if ($rbi !== null && $rbi > 0) {
            $said .= ', ' . $rbi . ' driven in';
        }

        return $said;
    }

    protected function margin(string $winner, int $margin): string
    {
        return match (true) {
            $margin === 1 => sprintf('%s took it by one.', $winner),
            $margin <= 3 => sprintf('%s won it by %d.', $winner, $margin),
            $margin <= 6 => sprintf('%s had it comfortably.', $winner),
            default => sprintf('%s were never troubled.', $winner),
        };
    }

    protected function drawnGame(): string
    {
        /*
         * 🚨 Baseball plays extra innings until somebody wins, so a tie means a
         * suspended or called game — genuinely rare, and worth saying plainly
         * rather than dressing up as a result.
         */
        return 'It finished level, which means it did not finish.';
    }

    /** `2-4` → "2 for 4", which is how anybody says it out loud. */
    protected function hitLine(string $line): string
    {
        $parts = explode('-', $line, 2);

        return count($parts) === 2 ? $parts[0] . ' for ' . $parts[1] : $line;
    }
}
