<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services\Sports;

/**
 * American football — college and professional alike.
 *
 * 🚨 One class for both, and that is not laziness. The vocabulary is identical
 * and so are the thresholds: twenty-eight points is a rout in either, a
 * quarterback's line reads the same, and the box score arrives in the same
 * shape. A separate NFL class would be a copy with the same numbers in it,
 * which is a copy that eventually disagrees with itself.
 */
final class Gridiron extends Sport
{
    public function key(): string
    {
        return 'gridiron';
    }

    public function name(): string
    {
        return 'American football';
    }

    /**
     * 🚨 Ordered as somebody reads a game rather than as the feed lists them:
     * how far each side moved the ball, how they moved it, and then the three
     * things that decide close games. Possession is last because it is the
     * least explanatory number on the list and the one most often mistaken for
     * one that matters.
     */
    public function comparison(): array
    {
        return [
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
    }

    public function leaderCategories(): array
    {
        return [
            'passing' => 'YDS',
            'rushing' => 'YDS',
            'receiving' => 'YDS',
        ];
    }

    public function narrative(array $sides, string $home, string $away): array
    {
        $out = [];

        $homeYards = $this->number($sides['home']['stats']['totalYards'] ?? null);
        $awayYards = $this->number($sides['away']['stats']['totalYards'] ?? null);

        if ($homeYards !== null && $awayYards !== null) {
            [$leader, $trailer, $more, $fewer] = $homeYards >= $awayYards
                ? [$home, $away, $homeYards, $awayYards]
                : [$away, $home, $awayYards, $homeYards];

            $out[] = $more - $fewer < 40
                // Two teams within forty yards did not win it there, and saying
                // one "out-gained" the other implies they did.
                ? sprintf('There was almost nothing in the yardage — %d to %d.', $more, $fewer)
                : sprintf('%s out-gained %s %d to %d.', $leader, $trailer, $more, $fewer);
        }

        $homeAway = $this->number($sides['home']['stats']['turnovers'] ?? null);
        $awayAway = $this->number($sides['away']['stats']['turnovers'] ?? null);

        if ($homeAway !== null && $awayAway !== null && $homeAway + $awayAway > 0) {
            $out[] = $homeAway === $awayAway
                ? sprintf('They gave it away %s each.', $this->times($homeAway))
                : sprintf(
                    '%s gave it away %s, %s %s.',
                    $homeAway > $awayAway ? $home : $away,
                    $this->times(max($homeAway, $awayAway)),
                    $homeAway > $awayAway ? $away : $home,
                    min($homeAway, $awayAway) === 0 ? 'not at all' : $this->times(min($homeAway, $awayAway)),
                );
        }

        return $out;
    }

    public function playerLine(string $category, array $stats): string
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
            'passing' => sprintf(
                '%s for %d%s%s',
                (string) ($stats['C/ATT'] ?? ''),
                $yards,
                $scores,
                $this->picks($this->number($stats['INT'] ?? null) ?? 0),
            ),
            'rushing' => sprintf('%s for %d%s', $this->carries($this->number($stats['CAR'] ?? null)), $yards, $scores),
            'receiving' => sprintf('%s for %d%s', $this->catches($this->number($stats['REC'] ?? null)), $yards, $scores),
            default => '',
        };
    }

    /**
     * 🚨 A draw is all but impossible here — it needs a full overtime that
     * settles nothing — so it is remarked on rather than reported flatly.
     */
    protected function drawnGame(): string
    {
        return 'It finished level, which almost never happens.';
    }

    protected function margin(string $winner, int $margin): string
    {
        return match (true) {
            $margin <= 3 => $winner . ' took it by ' . $margin . '.',
            $margin >= 28 => $winner . ' were never troubled.',
            $margin >= 17 => $winner . ' had it comfortably.',
            default => $winner . ' won it by ' . $margin . '.',
        };
    }

    /**
     * 🚨 Its own wording rather than `times()`. "With once picked off" is what
     * counting words give you when they are reused for something that is not a
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

    private function carries(?int $n): string
    {
        return $n === null ? 'ran' : ($n === 1 ? 'one carry' : $n . ' carries');
    }

    private function catches(?int $n): string
    {
        return $n === null ? 'caught' : ($n === 1 ? 'one catch' : $n . ' catches');
    }
}
