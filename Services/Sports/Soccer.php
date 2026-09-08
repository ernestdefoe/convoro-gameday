<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services\Sports;

/**
 * Association football — the Premier League, MLS and everything shaped like
 * them.
 *
 * 🚨 The sport the seam was proved against, because it disagrees with gridiron
 * about nearly everything a recap says. A draw is an ordinary Saturday rather
 * than a curiosity; three goals is a hammering where three points is nothing;
 * possession is a percentage rather than a clock; and a team that had eighteen
 * shots to six can perfectly well have lost. If the same `Recap` writes a
 * readable post about both of these, it will write one about basketball.
 *
 * Statistic names are ESPN's own, taken from a real Everton 2–2 Manchester
 * United box score rather than guessed.
 */
final class Soccer extends Sport
{
    public function key(): string
    {
        return 'soccer';
    }

    public function name(): string
    {
        return 'Football (soccer)';
    }

    public function comparison(): array
    {
        return [
            'possessionPct' => 'Possession',
            'totalShots' => 'Shots',
            'shotsOnTarget' => 'On target',
            'wonCorners' => 'Corners',
            'saves' => 'Saves',
            'foulsCommitted' => 'Fouls',
            'offsides' => 'Offsides',
            'yellowCards' => 'Yellow cards',
            'redCards' => 'Red cards',
        ];
    }

    /**
     * 🚨 Empty, and deliberately so rather than left unfinished.
     *
     * ESPN's soccer summary carries no player box score at all — the response
     * this was built against has `boxscore.teams` and nothing else. Naming a
     * leading scorer would mean reading the goal events, which is a different
     * feed and a different piece of work; inventing a category that is always
     * empty would put an empty paragraph under every match.
     */
    public function leaderCategories(): array
    {
        return [];
    }

    public function playerLine(string $category, array $stats): string
    {
        return '';
    }

    public function drawsHappen(): bool
    {
        return true;
    }

    public function narrative(array $sides, string $home, string $away): array
    {
        $out = [];

        $homeShots = $this->number($sides['home']['stats']['totalShots'] ?? null);
        $awayShots = $this->number($sides['away']['stats']['totalShots'] ?? null);
        $homeOn = $this->number($sides['home']['stats']['shotsOnTarget'] ?? null);
        $awayOn = $this->number($sides['away']['stats']['shotsOnTarget'] ?? null);

        if ($homeShots !== null && $awayShots !== null && $homeShots + $awayShots > 0) {
            $out[] = $homeOn !== null && $awayOn !== null
                ? sprintf(
                    '%s had %d shots to %s\'s %d, %d on target against %d.',
                    $home,
                    $homeShots,
                    $away,
                    $awayShots,
                    $homeOn,
                    $awayOn,
                )
                : sprintf('%s had %d shots to %s\'s %d.', $home, $homeShots, $away, $awayShots);
        }

        $possession = $this->decimal($sides['home']['stats']['possessionPct'] ?? null);

        if ($possession !== null) {
            /*
             * 🚨 Only when it was lopsided. Fifty-two per cent of the ball is
             * not a fact about a match, and a recap that reports it every week
             * teaches people to stop reading the line that sometimes matters.
             */
            $side = $possession >= 50 ? $home : $away;
            $share = $possession >= 50 ? $possession : 100 - $possession;

            if ($share >= 58) {
                $out[] = sprintf('%s had %s%% of the ball.', $side, rtrim(rtrim(number_format($share, 1), '0'), '.'));
            }
        }

        return $out;
    }

    /**
     * A draw is an ordinary result, so it is reported rather than remarked on —
     * and the goalless one gets its own word, because everybody has one for it.
     */
    protected function drawnGame(): string
    {
        return 'A draw.';
    }

    /** A possession share is a percentage and has to say so. */
    public function formatStat(string $key, string $value): string
    {
        return $key === 'possessionPct' && $value !== '' ? $value . '%' : $value;
    }

    protected function margin(string $winner, int $margin): string
    {
        return match (true) {
            $margin === 1 => $winner . ' edged it.',
            $margin === 2 => $winner . ' won by two.',
            $margin >= 4 => $winner . ' were far too good.',
            default => $winner . ' won comfortably.',
        };
    }
}
