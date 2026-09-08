<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services;

use Convoro\Extensions\Gameday\Services\Sports\Gridiron;
use Convoro\Extensions\Gameday\Services\Sports\Sport;

/**
 * The post a thread ends with.
 *
 * 🚨 A pure function of the game and its box score, returning a document. No
 * database, no clock, no settings — so what it writes can be read in a test
 * rather than inferred from a post somewhere.
 *
 * 🚨 It writes what happened, not everything that is known. A box score carries
 * thirty-five team statistics; a recap that prints all of them is a table
 * nobody reads, and the point of a recap is that somebody coming back on Sunday
 * learns the game in ten seconds.
 *
 * 🚨 The STRUCTURE lives here and the WORDS live in the sport. Every game has a
 * score, a result, a sentence or two on how it went, the players worth naming
 * and a comparison — that is the same in every sport and it is this class. That
 * a team out-gains another by 350 yards to 284, or has 62% of the ball; that
 * twenty-eight points is a rout and three goals is a hammering; that a draw is
 * ordinary in one and a curiosity in the other — that is `Sports\Sport`, and it
 * is why adding a league is a class of sentences rather than a second `Recap`.
 *
 * Everything below the score is conditional. A game whose box score never
 * arrived still gets a recap — the one it always got — and a sport with no
 * player breakdown at all, as ESPN's soccer feed has none, gets the comparison
 * without the names. Nothing here renders a heading over an empty section.
 */
final class Recap
{
    public function __construct(private Sport $sport = new Gridiron())
    {
    }

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
            $this->line($this->sport->outcome($home, $away, $homeScore, $awayScore)),
        ];

        $stats = $this->sides($box);

        if ($stats !== null) {
            foreach ($this->sport->narrative($stats, $home, $away) as $sentence) {
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

    /**
     * The players worth naming, as one sentence.
     *
     * 🚨 Grouped by PLAYER, not listed by category.
     *
     * A dual-threat quarterback leads both passing and rushing, which is
     * ordinary in college football and read like this before it was fixed:
     *
     *   Keelon Russell 18/30 for 256; Keelon Russell 13 carries for 86
     *
     * The same man twice in one sentence, as though he were two people. Found
     * on a real game — Alabama against East Carolina — the day this shipped.
     * The categories still decide the order, because the first one somebody
     * appears in is where they sit.
     *
     * @param array<string, array{name: string, stats: array<string, string>}> $leaders
     */
    private function leaderLine(array $leaders): string
    {
        $byPlayer = [];

        foreach (array_keys($this->sport->leaderCategories()) as $category) {
            $leader = $leaders[$category] ?? null;

            if (!is_array($leader) || ($leader['name'] ?? '') === '') {
                continue;
            }

            $said = $this->sport->playerLine($category, (array) ($leader['stats'] ?? []));

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

        foreach ($this->sport->comparison() as $key => $label) {
            $homeValue = trim((string) ($sides['home']['stats'][$key] ?? ''));
            $awayValue = trim((string) ($sides['away']['stats'][$key] ?? ''));

            // A row neither side has a figure for is not a row.
            if ($homeValue === '' && $awayValue === '') {
                continue;
            }

            $rows[] = $this->row([
                $this->headerCell($label),
                $this->cell($homeValue === '' ? '—' : $this->sport->formatStat($key, $homeValue)),
                $this->cell($awayValue === '' ? '—' : $this->sport->formatStat($key, $awayValue)),
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
