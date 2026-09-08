<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services;

/**
 * The post a thread ends with.
 *
 * 🚨 A pure function of the game and its box score, returning a document. No
 * database, no clock, no settings — so what it writes can be read in a test
 * rather than inferred from a post somewhere, and so the same prose can be
 * built again on another platform by handing it the same two arrays.
 *
 * 🚨 It writes what happened, not everything that is known. A box score carries
 * thirty-five team statistics and ten categories of player figures; a recap
 * that prints all of them is a table nobody reads, and the point of a recap is
 * that somebody coming back on Sunday learns the game in ten seconds. So: the
 * score, one or two sentences on how it was won, the handful of players worth
 * naming, and the comparison table underneath for anybody who wants it.
 *
 * Everything below the score is conditional. A game whose box score never
 * arrived still gets a recap — the one it always got — and a game with team
 * statistics but no player breakdown gets the table without the names. Nothing
 * here renders a heading over an empty section.
 */
final class Recap
{
    /**
     * The rows of the comparison table, in the order they are shown.
     *
     * 🚨 Ordered as somebody reads a game rather than as the feed lists them:
     * how far each side moved the ball, how they moved it, and then the three
     * things that decide close games. `possessionTime` is last because it is
     * the least explanatory number on the list and the one most often
     * mistaken for one that matters.
     */
    private const TABLE = [
        'firstDowns' => 'First downs',
        'totalYards' => 'Total yards',
        'netPassingYards' => 'Passing yards',
        'rushingYards' => 'Rushing yards',
        'thirdDownEff' => 'Third down',
        'fourthDownEff' => 'Fourth down',
        'totalPenaltiesYards' => 'Penalties',
        'turnovers' => 'Turnovers',
        'possessionTime' => 'Possession',
    ];

    /** How a player's line is written, per category. */
    private const LINES = [
        'passing' => ['C/ATT', 'YDS', 'TD', 'INT'],
        'rushing' => ['CAR', 'YDS', 'TD'],
        'receiving' => ['REC', 'YDS', 'TD'],
    ];

    /**
     * @param array<string, mixed> $game home_name, away_name, home_score, away_score
     * @param array<string, mixed>|null $box the normalised box score, when there is one
     * @return array<string, mixed> a TipTap document
     */
    public function document(array $game, ?array $box = null): array
    {
        $home = (string) ($game['home_name'] ?? '');
        $away = (string) ($game['away_name'] ?? '');
        $homeScore = (int) ($game['home_score'] ?? 0);
        $awayScore = (int) ($game['away_score'] ?? 0);

        $content = [
            $this->paragraph([$this->bold(sprintf(
                'Final: %s %d, %s %d.',
                $home,
                $homeScore,
                $away,
                $awayScore
            ))]),
            $this->line($this->outcome($home, $away, $homeScore, $awayScore)),
        ];

        $stats = $this->sides($box);

        if ($stats !== null) {
            foreach ($this->howItWent($stats, $home, $away) as $sentence) {
                $content[] = $this->line($sentence);
            }

            foreach ([['home', $home], ['away', $away]] as [$side, $name]) {
                $line = $this->leaderLine($stats[$side]['leaders'] ?? []);

                if ($line !== '') {
                    $content[] = $this->paragraph([
                        $this->bold($name . ': '),
                        $this->text($line),
                    ]);
                }
            }

            $table = $this->table($stats, $home, $away);

            if ($table !== null) {
                $content[] = $table;
            }
        }

        /*
         * 🚨 Last, and always. The sentence that tells somebody the thread is
         * not closed — the single most common question under a finished game
         * thread on any forum that has ever had one.
         */
        $content[] = $this->line(
            'The thread is an ordinary topic again now — still here, still searchable.'
        );

        return ['type' => 'doc', 'content' => $content];
    }

    /** Whether a box score has enough in it to say anything with. */
    public function usable(?array $box): bool
    {
        return $this->sides($box) !== null;
    }

    /* ------------------------------------------------------------- the prose */

    private function outcome(string $home, string $away, int $homeScore, int $awayScore): string
    {
        if ($homeScore === $awayScore) {
            return 'It finished level.';
        }

        $winner = $homeScore > $awayScore ? $home : $away;
        $margin = abs($homeScore - $awayScore);

        /*
         * 🚨 The margin is described, not just stated. "Won it" is true of a
         * one-point game and a fifty-point one, and a recap that reads the same
         * either way is a recap that tells you nothing you could not see from
         * the score.
         */
        return match (true) {
            $margin <= 3 => $winner . ' took it by ' . $margin . '.',
            $margin >= 28 => $winner . ' were never troubled.',
            $margin >= 17 => $winner . ' had it comfortably.',
            default => $winner . ' won it by ' . $margin . '.',
        };
    }

    /**
     * One or two sentences on how the game went.
     *
     * 🚨 Each is earned. A yardage line is only interesting when the two are
     * far enough apart to mean something, a turnover line only when somebody
     * actually lost the ball, and neither is printed to fill space. A recap
     * that always has three sentences has three sentences of nothing on the
     * day nothing happened.
     *
     * @param array{home: array<string, mixed>, away: array<string, mixed>} $sides
     * @return list<string>
     */
    private function howItWent(array $sides, string $home, string $away): array
    {
        $out = [];

        $homeYards = $this->number($sides['home']['stats']['totalYards'] ?? null);
        $awayYards = $this->number($sides['away']['stats']['totalYards'] ?? null);

        if ($homeYards !== null && $awayYards !== null) {
            [$leader, $trailer, $more, $fewer] = $homeYards >= $awayYards
                ? [$home, $away, $homeYards, $awayYards]
                : [$away, $home, $awayYards, $homeYards];

            $out[] = $more - $fewer < 40
                // Two teams within forty yards of each other did not win it
                // there, and saying one "out-gained" the other implies they did.
                ? sprintf('There was almost nothing in the yardage — %d to %d.', $more, $fewer)
                : sprintf('%s out-gained %s %d to %d.', $leader, $trailer, $more, $fewer);
        }

        $homeAway = $this->number($sides['home']['stats']['turnovers'] ?? null);
        $awayAway = $this->number($sides['away']['stats']['turnovers'] ?? null);

        if ($homeAway !== null && $awayAway !== null && $homeAway + $awayAway > 0) {
            $out[] = match (true) {
                $homeAway === $awayAway => sprintf(
                    'They gave it away %s each.',
                    $this->times($homeAway)
                ),
                default => sprintf(
                    '%s gave it away %s, %s %s.',
                    $homeAway > $awayAway ? $home : $away,
                    $this->times(max($homeAway, $awayAway)),
                    $homeAway > $awayAway ? $away : $home,
                    min($homeAway, $awayAway) === 0 ? 'not at all' : $this->times(min($homeAway, $awayAway)),
                ),
            };
        }

        return $out;
    }

    /**
     * 🚨 Its own wording rather than `times()`. "With once picked off" is what
     * counting words give you when they are reused for a thing that is not a
     * count of occasions, and it reads as a typo.
     */
    private function picks(int $n): string
    {
        return match (true) {
            $n < 1 => '',
            $n === 1 => ', with an interception',
            default => ', with ' . $this->word($n) . ' interceptions',
        };
    }

    private function times(int $n): string
    {
        return match ($n) {
            0 => 'not at all',
            1 => 'once',
            2 => 'twice',
            default => $n . ' times',
        };
    }

    /**
     * The players worth naming, as one sentence.
     *
     * @param array<string, array{name: string, stats: array<string, string>}> $leaders
     */
    private function leaderLine(array $leaders): string
    {
        /*
         * 🚨 Grouped by PLAYER, not listed by category.
         *
         * A dual-threat quarterback leads both passing and rushing, which is
         * ordinary in college football and read like this before it was fixed:
         *
         *   Demond Williams Jr. 24/35 for 268 and a touchdown;
         *   Demond Williams Jr. 7 carries for 61 and a touchdown
         *
         * The same man twice in one sentence, as though he were two people.
         * Found on a real game the day this shipped. His figures belong
         * together, and the categories are still in reading order because the
         * first one he appears in decides where he sits.
         */
        $byPlayer = [];

        foreach (self::LINES as $category => $figures) {
            $leader = $leaders[$category] ?? null;

            if (!is_array($leader) || ($leader['name'] ?? '') === '') {
                continue;
            }

            $said = $this->player($category, (array) ($leader['stats'] ?? []));

            if ($said !== '') {
                $byPlayer[(string) $leader['name']][] = $said;
            }
        }

        $parts = [];

        foreach ($byPlayer as $name => $lines) {
            $parts[] = $name . ' ' . implode(', and ', $lines);
        }

        return $parts === [] ? '' : implode('; ', $parts) . '.';
    }

    /**
     * One player's line, written the way somebody would say it.
     *
     * @param array<string, string> $stats
     */
    private function player(string $category, array $stats): string
    {
        $yards = $this->number($stats['YDS'] ?? null);

        if ($yards === null) {
            return '';
        }

        $touchdowns = $this->number($stats['TD'] ?? null) ?? 0;
        $scores = match (true) {
            $touchdowns < 1 => '',
            $touchdowns === 1 => ' and a touchdown',
            default => ' and ' . $this->word($touchdowns) . ' touchdowns',
        };

        return match ($category) {
            'passing' => trim(sprintf(
                '%s for %d%s%s',
                (string) ($stats['C/ATT'] ?? ''),
                $yards,
                $scores,
                // Interceptions are only worth the words when there were some.
                $this->picks($this->number($stats['INT'] ?? null) ?? 0),
            )),
            'rushing' => sprintf(
                '%s for %d%s',
                $this->carries($this->number($stats['CAR'] ?? null)),
                $yards,
                $scores,
            ),
            'receiving' => sprintf(
                '%s for %d%s',
                $this->catches($this->number($stats['REC'] ?? null)),
                $yards,
                $scores,
            ),
            default => '',
        };
    }

    private function carries(?int $n): string
    {
        return $n === null ? 'ran' : ($n === 1 ? 'one carry' : $n . ' carries');
    }

    private function catches(?int $n): string
    {
        return $n === null ? 'caught' : ($n === 1 ? 'one catch' : $n . ' catches');
    }

    private function word(int $n): string
    {
        return match ($n) {
            2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
            default => (string) $n,
        };
    }

    /* ------------------------------------------------------------- the table */

    /**
     * @param array{home: array<string, mixed>, away: array<string, mixed>} $sides
     * @return array<string, mixed>|null
     */
    private function table(array $sides, string $home, string $away): ?array
    {
        $rows = [$this->row([
            $this->headerCell(''),
            $this->headerCell($home),
            $this->headerCell($away),
        ])];

        foreach (self::TABLE as $key => $label) {
            $homeValue = trim((string) ($sides['home']['stats'][$key] ?? ''));
            $awayValue = trim((string) ($sides['away']['stats'][$key] ?? ''));

            // A row neither side has a figure for is not a row.
            if ($homeValue === '' && $awayValue === '') {
                continue;
            }

            $rows[] = $this->row([
                $this->headerCell($label),
                $this->cell($homeValue === '' ? '—' : $homeValue),
                $this->cell($awayValue === '' ? '—' : $awayValue),
            ]);
        }

        // Only the header: nothing to compare, so no table.
        return count($rows) < 2 ? null : ['type' => 'table', 'content' => $rows];
    }

    /* -------------------------------------------------------------- plumbing */

    /**
     * @param array<string, mixed>|null $box
     * @return array{home: array<string, mixed>, away: array<string, mixed>}|null
     */
    private function sides(?array $box): ?array
    {
        if (!is_array($box) || !isset($box['home'], $box['away'])) {
            return null;
        }

        $home = is_array($box['home']) ? $box['home'] : [];
        $away = is_array($box['away']) ? $box['away'] : [];

        // A document with two sides and no statistics in either is a document
        // that would render an empty table under an empty paragraph.
        if (($home['stats'] ?? []) === [] && ($away['stats'] ?? []) === []) {
            return null;
        }

        return ['home' => $home, 'away' => $away];
    }

    /** A figure the feed wrote as a string, when it really is a number. */
    private function number(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    /** @param list<array<string, mixed>> $content */
    private function paragraph(array $content): array
    {
        return ['type' => 'paragraph', 'content' => $content];
    }

    private function line(string $text): array
    {
        return $this->paragraph([$this->text($text)]);
    }

    private function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    private function bold(string $text): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'bold']]];
    }

    /** @param list<array<string, mixed>> $cells */
    private function row(array $cells): array
    {
        return ['type' => 'tableRow', 'content' => $cells];
    }

    private function headerCell(string $text): array
    {
        return ['type' => 'tableHeader', 'content' => [$this->line($text)]];
    }

    private function cell(string $text): array
    {
        return ['type' => 'tableCell', 'content' => [$this->line($text)]];
    }
}
