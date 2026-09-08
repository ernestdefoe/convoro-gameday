<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services\Sports;

/**
 * What a sport says about itself.
 *
 * 🚨 The structure of a recap is the same everywhere and the WORDS are not.
 * Every game has a score, a winner or a draw, a couple of sentences on how it
 * went, the players worth naming and a comparison — that is `Recap`, and it
 * does not change. What changes is that a football team out-gains another by
 * 350 yards to 284 and a football team has 62% of the ball; that twenty-eight
 * points is a rout and three goals is a hammering; that one sport has carries
 * and another has shots on target; and that in one of them a draw is a normal
 * Saturday and in the other it is a curiosity.
 *
 * So a sport supplies vocabulary and thresholds, and nothing else. Adding a
 * league becomes a class of sentences rather than a change to how a recap is
 * built — which is the whole point, because the alternative is four `Recap`
 * classes that drift.
 *
 * 🚨 A sport is NOT a provider. Where the numbers come from is Picks' business;
 * this is only what to call them once they have arrived.
 */
abstract class Sport
{
    /** Stable identifier, stored in settings and never shown to anybody. */
    abstract public function key(): string;

    /** What an operator sees in a dropdown. */
    abstract public function name(): string;

    /**
     * The comparison, in the order somebody reads a game.
     *
     * @return array<string, string> stat key => label
     */
    abstract public function comparison(): array;

    /**
     * One or two sentences on how the game went.
     *
     * 🚨 Each has to be EARNED. A recap that always has three sentences has
     * three sentences of nothing on the day nothing happened, and this is where
     * that judgement lives because what counts as remarkable is a fact about
     * the sport: forty yards is nothing in football and a two-goal swing is
     * most of a football match.
     *
     * @param  array{home: array<string, mixed>, away: array<string, mixed>} $sides
     * @return list<string>
     */
    abstract public function narrative(array $sides, string $home, string $away): array;

    /**
     * Which player categories are worth naming somebody in, and which figure
     * decides who led each.
     *
     * @return array<string, string> category => the deciding figure
     */
    abstract public function leaderCategories(): array;

    /**
     * One player's line, written the way somebody would say it.
     *
     * @param array<string, string> $stats
     */
    abstract public function playerLine(string $category, array $stats): string;

    /**
     * Whether a draw is an ordinary result.
     *
     * 🚨 Not decoration. In a sport where draws are ordinary the recap says so
     * plainly; in one where they are all but impossible, saying "it finished
     * level" about a game that went to overtime would be wrong.
     */
    public function drawsHappen(): bool
    {
        return false;
    }

    /**
     * The result, described rather than stated.
     *
     * 🚨 "Won it" is equally true of a one-point game and a fifty-point one,
     * which makes it worth nothing in either. The thresholds belong to the
     * sport: twenty-eight points is a rout in football and cannot happen in
     * football.
     */
    public function outcome(string $home, string $away, int $homeScore, int $awayScore): string
    {
        if ($homeScore === $awayScore) {
            return $this->drawnGame();
        }

        $winner = $homeScore > $awayScore ? $home : $away;

        return $this->margin($winner, abs($homeScore - $awayScore));
    }

    abstract protected function margin(string $winner, int $margin): string;

    /**
     * How a figure reads in the comparison.
     *
     * 🚨 The unit lives with the sport, not with the feed. ESPN answers a
     * possession share as `45.4` and a shot count as `18`, and a comparison
     * that prints both bare makes the first one look like a count of
     * something. The default is the value exactly as it arrived, because for
     * most statistics in most sports that is right.
     */
    public function formatStat(string $key, string $value): string
    {
        return $value;
    }

    protected function drawnGame(): string
    {
        return 'It finished level.';
    }

    /* --------------------------------------------------------- shared words */

    /** A figure the feed wrote as a string, when it really is a number. */
    protected function number(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    /** The same, for figures that are genuinely fractional — a possession share. */
    protected function decimal(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? (float) $value : null;
    }

    protected function word(int $n): string
    {
        return match ($n) {
            2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
            default => (string) $n,
        };
    }

    protected function times(int $n): string
    {
        return match ($n) {
            0 => 'not at all',
            1 => 'once',
            2 => 'twice',
            default => $n . ' times',
        };
    }
}
