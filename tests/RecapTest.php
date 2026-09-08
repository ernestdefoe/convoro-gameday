<?php

declare(strict_types=1);

/*
 * The post a game thread ends with.
 *
 * 🚨 Asserted on the WORDS, not on the structure. A recap is prose somebody
 * reads on Sunday morning, and "the document has six nodes" would pass for a
 * recap that said something wrong in all six of them. The box score is the real
 * one Picks stores — Notre Dame 41, Wisconsin 13, week one of 2026.
 *
 * Nothing here touches the database or the network.
 */

use Convoro\Engine\Support\TipTapRenderer;
use Convoro\Extensions\Gameday\Services\Recap;
use Convoro\Extensions\Gameday\Services\Sports\Diamond;
use Convoro\Extensions\Gameday\Services\Sports\Hardwood;
use Convoro\Extensions\Gameday\Services\Sports\Ice;
use Convoro\Extensions\Gameday\Services\Sports\Soccer;
use Convoro\Extensions\Gameday\Services\Sports\Sports;

$fixture = json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/../picks/tests/fixtures/cfbd-box-score.json'),
    true
);

/** The box score in the shape Picks stores, built by Picks' own normaliser. */
$box = (new Convoro\Extensions\Picks\Services\BoxScores(
    Convoro\Engine\Convoro::getInstance()->make('db'),
    new Convoro\Extensions\Picks\Services\Sources\Cfbd(
        new Convoro\Extensions\Picks\Services\Http(),
        new Convoro\Extensions\Picks\Services\Settings(Convoro\Engine\Convoro::getInstance()->make('db')),
    ),
    new Convoro\Extensions\Picks\Services\Settings(Convoro\Engine\Convoro::getInstance()->make('db')),
))->normalise((int) $fixture['game'], $fixture['teams'], $fixture['players']);

$game = [
    'home_name' => 'Notre Dame',
    'away_name' => 'Wisconsin',
    'home_score' => 41,
    'away_score' => 13,
];

/**
 * A box score from ESPN, normalised by Picks' own code.
 *
 * 🚨 Built by `../picks/tests/fixtures` + the ESPN adapter rather than typed
 * here, and the SAME files back the Flarum build's suite. A fixture somebody
 * wrote only proves the recap agrees with whoever wrote it.
 */
$espn = static fn (string $sport): array => json_decode(
    (string) file_get_contents(__DIR__ . '/fixtures/espn-' . $sport . '.json'),
    true
);

/** The recap as a reader sees it: rendered, tags stripped, one line per block. */
$read = static function (array $document): string {
    $html = (new TipTapRenderer())->render($document);

    return html_entity_decode(strip_tags(str_replace(['</p>', '</tr>'], "\n", $html)));
};

return [
    'a game with no box score still gets its recap' => function () use ($read, $game) {
        /*
         * 🚨 The behaviour every game had before statistics existed, and the
         * one a game the provider never covered still gets. A recap that
         * depends on a box score is a thread with no ending on the day the
         * feed is late.
         */
        $text = $read((new Recap())->document($game, null));

        assertTrue(str_contains($text, 'Final: Notre Dame 41, Wisconsin 13.'));
        assertTrue(str_contains($text, 'still here, still searchable'));
        assertFalse(str_contains($text, 'out-gained'), 'nothing may be claimed about a game nobody has figures for');
    },

    'the margin is described rather than just stated' => function () use ($read) {
        $recap = new Recap();
        $say = static fn (int $home, int $away): string => $read($recap->document([
            'home_name' => 'Alabama', 'away_name' => 'Auburn',
            'home_score' => $home, 'away_score' => $away,
        ], null));

        /*
         * "Won it" is equally true of a one-point game and a fifty-point one,
         * which makes it worth nothing in either.
         */
        assertTrue(str_contains($say(24, 21), 'Alabama took it by 3.'));
        assertTrue(str_contains($say(31, 20), 'Alabama won it by 11.'));
        assertTrue(str_contains($say(38, 17), 'Alabama had it comfortably.'));
        assertTrue(str_contains($say(52, 0), 'Alabama were never troubled.'));
        /*
         * 🚨 Remarked on rather than reported. A drawn game of American
         * football needs a full overtime that settles nothing, and saying "it
         * finished level" as flatly as a soccer recap would understate the
         * strangest result the sport has. Football's own wording is asserted
         * separately.
         */
        assertTrue(str_contains($say(21, 21), 'It finished level, which almost never happens.'));
    },

    'it says how the game was won' => function () use ($read, $game, $box) {
        $text = $read((new Recap())->document($game, $box));

        assertTrue(str_contains($text, 'Notre Dame out-gained Wisconsin 350 to 284.'), $text);
        assertTrue(str_contains($text, 'Wisconsin gave it away twice, Notre Dame not at all.'), $text);
    },

    'a close game does not claim somebody out-gained anybody' => function () use ($read) {
        /*
         * 🚨 Thirty yards apart is not a team being out-gained, and saying so
         * implies the game was won there. The threshold exists because the
         * sentence is a claim, not a subtraction.
         */
        $text = $read((new Recap())->document(
            ['home_name' => 'Ohio State', 'away_name' => 'Michigan', 'home_score' => 20, 'away_score' => 17],
            [
                'home' => ['team' => 'Ohio State', 'points' => 20, 'stats' => ['totalYards' => '331'], 'leaders' => []],
                'away' => ['team' => 'Michigan', 'points' => 17, 'stats' => ['totalYards' => '308'], 'leaders' => []],
            ],
        ));

        assertFalse(str_contains($text, 'out-gained'));
        assertTrue(str_contains($text, 'There was almost nothing in the yardage — 331 to 308.'), $text);
    },

    'a clean game says nothing about turnovers' => function () use ($read) {
        // Nobody lost the ball, so there is nothing to say — and a recap that
        // always has a turnover sentence has one of nothing most weeks.
        $text = $read((new Recap())->document(
            ['home_name' => 'Iowa', 'away_name' => 'Purdue', 'home_score' => 14, 'away_score' => 7],
            [
                'home' => ['team' => 'Iowa', 'points' => 14, 'stats' => ['turnovers' => '0'], 'leaders' => []],
                'away' => ['team' => 'Purdue', 'points' => 7, 'stats' => ['turnovers' => '0'], 'leaders' => []],
            ],
        ));

        assertFalse(str_contains($text, 'gave it away'));
    },

    'the players are named the way somebody would say it' => function () use ($read, $game, $box) {
        $text = $read((new Recap())->document($game, $box));

        assertTrue(str_contains(
            $text,
            'C.J. Carr 19/29 for 239 and two touchdowns'
        ), $text);
        assertTrue(str_contains($text, 'Aneyas Williams 20 carries for 90 and two touchdowns'), $text);
        assertTrue(str_contains($text, 'Jordan Faison 4 catches for 81'), $text);

        /*
         * 🚨 "With once picked off" is what counting words give you when they
         * are reused for something that is not a count of occasions, and it
         * reads as a typo. Interceptions get their own wording.
         */
        assertTrue(str_contains($text, 'and a touchdown, with an interception'), $text);
        assertFalse(str_contains($text, 'once picked off'));
    },

    'a player who leads two categories is named once' => function () use ($read) {
        /*
         * 🚨 A dual-threat quarterback is ordinary in college football, and
         * before this the recap named him twice in the same sentence as though
         * he were two people. Found on a real game — Washington's Demond
         * Williams Jr., who led both passing and rushing — the day this
         * shipped.
         */
        $text = $read((new Recap())->document(
            ['home_name' => 'Washington', 'away_name' => 'Washington State',
             'home_score' => 24, 'away_score' => 10],
            [
                'home' => [
                    'team' => 'Washington', 'points' => 24,
                    'stats' => ['totalYards' => '402'],
                    'leaders' => [
                        'passing' => ['name' => 'Demond Williams Jr.',
                            'stats' => ['C/ATT' => '24/35', 'YDS' => '268', 'TD' => '1', 'INT' => '0']],
                        'rushing' => ['name' => 'Demond Williams Jr.',
                            'stats' => ['CAR' => '7', 'YDS' => '61', 'TD' => '1']],
                        'receiving' => ['name' => 'Chris Lawson',
                            'stats' => ['REC' => '4', 'YDS' => '87', 'TD' => '0']],
                    ],
                ],
                'away' => ['team' => 'Washington State', 'points' => 10,
                    'stats' => ['totalYards' => '242'], 'leaders' => []],
            ],
        ));

        assertTrue(str_contains(
            $text,
            'Demond Williams Jr. 24/35 for 268 and a touchdown, and 7 carries for 61 and a touchdown'
        ), $text);

        assertSame(1, substr_count($text, 'Demond Williams Jr.'), 'named once, not once per category');
        assertTrue(str_contains($text, 'Chris Lawson 4 catches for 87'), 'and everybody else still appears');
    },

    'the comparison table carries the figures that explain a game' => function () use ($game, $box) {
        $html = (new TipTapRenderer())->render((new Recap())->document($game, $box));

        foreach (['First downs', 'Total yards', 'Third down', 'Turnovers', 'Possession'] as $row) {
            assertTrue(str_contains($html, $row), $row . ' is missing from the table');
        }

        // The ratio and the clock survive as themselves rather than as numbers.
        assertTrue(str_contains($html, '3-9'));
        assertTrue(str_contains($html, '31:36'));

        // And it is a real table, so it is readable on a phone.
        assertTrue(str_contains($html, '<table>'));
    },

    'a row neither side has a figure for is not drawn' => function () use ($game) {
        $html = (new TipTapRenderer())->render((new Recap())->document($game, [
            'home' => ['team' => 'A', 'points' => 7, 'stats' => ['totalYards' => '200'], 'leaders' => []],
            'away' => ['team' => 'B', 'points' => 3, 'stats' => ['totalYards' => '180'], 'leaders' => []],
        ]));

        assertTrue(str_contains($html, 'Total yards'));
        assertFalse(str_contains($html, 'Possession'), 'an empty row is worse than a missing one');
    },

    /*
     * 🚨 The seam, proved against the sport that disagrees with gridiron about
     * nearly everything a recap says.
     *
     * The statistic names and the figures below are ESPN's own, from a real
     * Everton 2–2 Manchester United box score — not invented, because a made-up
     * payload only proves the code agrees with whoever made it up.
     */
    'a draw in football is an ordinary result, not a curiosity' => function () use ($read) {
        $soccer = new Recap(new Soccer());

        $text = $read($soccer->document(
            ['home_name' => 'Everton', 'away_name' => 'Manchester United',
             'home_score' => 2, 'away_score' => 2],
            [
                'home' => ['team' => 'Everton', 'points' => 2, 'leaders' => [], 'stats' => [
                    'possessionPct' => '45.4', 'totalShots' => '18', 'shotsOnTarget' => '6',
                    'wonCorners' => '4', 'yellowCards' => '3', 'saves' => '1',
                ]],
                'away' => ['team' => 'Manchester United', 'points' => 2, 'leaders' => [], 'stats' => [
                    'possessionPct' => '54.6', 'totalShots' => '9', 'shotsOnTarget' => '4',
                    'wonCorners' => '2', 'yellowCards' => '1', 'saves' => '4',
                ]],
            ],
        ));

        assertTrue(str_contains($text, 'A draw.'), $text);

        // 🚨 And NOT the gridiron wording. "It finished level, which almost
        // never happens" is true of American football and absurd here.
        assertFalse(str_contains($text, 'almost never happens'));

        assertTrue(str_contains($text, "Everton had 18 shots to Manchester United's 9, 6 on target against 4."), $text);
        assertTrue(str_contains($text, 'Possession'), 'the comparison is the sport\'s own');

        /*
         * 🚨 The unit lives with the sport. ESPN answers a possession share as
         * `45.4` and a shot count as `18`; printing both bare makes the first
         * look like a count of something.
         */
        assertTrue(str_contains($text, '45.4%'), $text);
        assertTrue(str_contains($text, 'Yellow cards'));
        assertFalse(str_contains($text, 'Total yards'), 'and carries none of gridiron\'s');
    },

    'a possession share is only remarked on when it was lopsided' => function () use ($read) {
        $soccer = new Recap(new Soccer());

        $even = $read($soccer->document(
            ['home_name' => 'A', 'away_name' => 'B', 'home_score' => 1, 'away_score' => 0],
            ['home' => ['stats' => ['possessionPct' => '52.0'], 'leaders' => []],
             'away' => ['stats' => ['possessionPct' => '48.0'], 'leaders' => []]],
        ));

        // Fifty-two per cent of the ball is not a fact about a match, and a
        // recap that reports it every week teaches people to stop reading.
        assertFalse(str_contains($even, 'of the ball'));

        $lopsided = $read($soccer->document(
            ['home_name' => 'A', 'away_name' => 'B', 'home_score' => 1, 'away_score' => 0],
            ['home' => ['stats' => ['possessionPct' => '67.5'], 'leaders' => []],
             'away' => ['stats' => ['possessionPct' => '32.5'], 'leaders' => []]],
        ));

        assertTrue(str_contains($lopsided, 'A had 67.5% of the ball.'), $lopsided);
    },

    'a sport with no player box score renders no empty paragraph' => function () use ($read) {
        /*
         * 🚨 ESPN's soccer summary carries `boxscore.teams` and nothing else —
         * there is no player breakdown to name a scorer from. Declaring a
         * category that is always empty would put a heading over nothing under
         * every match.
         */
        assertSame([], (new Soccer())->leaderCategories());

        $text = $read((new Recap(new Soccer()))->document(
            ['home_name' => 'A', 'away_name' => 'B', 'home_score' => 1, 'away_score' => 0],
            ['home' => ['stats' => ['totalShots' => '9'], 'leaders' => []],
             'away' => ['stats' => ['totalShots' => '4'], 'leaders' => []]],
        ));

        assertFalse(str_contains($text, 'A: '), $text);
    },

    /*
     * 🚨 The three sports added after the seam existed, each asserted against a
     * REAL ESPN box score run through Picks' own adapter and normaliser — a
     * Timberwolves/Bucks game, Braves/Phillies, Stars/Sabres. ESPN publishes no
     * documentation for that API, and the shape differences the adapter absorbs
     * are exactly the ones nobody would think to invent, so a hand-written
     * payload here would prove nothing at all.
     *
     * 🚨 The SAME fixtures back the Flarum build's suite, asserting the same
     * sentences. The two recaps are ports of one idea, and this is what stops
     * them becoming two ideas.
     */
    'basketball is described in basketball\'s words' => function () use ($read, $espn) {
        $text = $read((new Recap(new Hardwood()))->document(
            ['home_name' => 'Milwaukee Bucks', 'away_name' => 'Minnesota Timberwolves',
             'home_score' => 103, 'away_score' => 106],
            $espn('nba'),
        ));

        /*
         * 🚨 Three points is a rout in football and a coin toss here. Reusing
         * gridiron's thresholds would call every basketball game comfortable.
         */
        assertTrue(str_contains($text, 'Minnesota Timberwolves got out with it, by 3.'), $text);
        assertFalse(str_contains($text, 'were never troubled'), 'a three-point game read as a rout');

        // The one line basketball has that nothing else does.
        assertTrue(str_contains(
            $text,
            'Giannis Antetokounmpo 23 points, 13 rebounds, 10 assists — a triple-double'
        ), $text);

        /*
         * 🚨 And a quiet line stays quiet. Every basketball line would otherwise
         * read "25 points, 3 rebounds, 2 assists" — three facts where one was
         * interesting.
         */
        assertTrue(str_contains($text, 'Anthony Edwards 25 points.'), $text);

        assertTrue(str_contains($text, 'Points in the paint'), 'the comparison is basketball\'s own');
        assertFalse(str_contains($text, 'Total yards'), 'gridiron vocabulary leaked in');
    },

    'baseball reads the group a figure came from' => function () use ($read, $espn) {
        $text = $read((new Recap(new Diamond()))->document(
            ['home_name' => 'Philadelphia Phillies', 'away_name' => 'Atlanta Braves',
             'home_score' => 1, 'away_score' => 0],
            $espn('mlb'),
        ));

        assertTrue(str_contains($text, 'Philadelphia Phillies took it by one.'), $text);
        assertTrue(str_contains($text, 'Philadelphia Phillies went deep once.'), $text);

        // Batting and pitching are different jobs and read differently.
        assertTrue(str_contains($text, 'Kyle Schwarber 2 for 4, a home run, 1 driven in'), $text);
        assertTrue(str_contains($text, 'Jesus Luzardo 9.0 innings, no earned runs, 12 struck out'), $text);

        /*
         * 🚨 The prefix, proved end to end. `hits` means three different things
         * in ESPN's three baseball groups, and unprefixed the fielding figure
         * would be printed as the batting line — a number that looks entirely
         * plausible and is about somebody else.
         */
        assertTrue(str_contains($text, 'Hits'), $text);
        assertTrue(str_contains($text, 'Errors'), $text);

        /*
         * 🚨 A hitless night with nothing driven in is not worth a name. The
         * batting leader is whoever drove in the most runs, and in a 1–0 game
         * that is everybody, tied on nothing — so the first name in the order
         * won it and "Ronald Acuna Jr. 0 for 3" was printed as the highlight.
         */
        assertFalse(str_contains($text, '0 for 3'), 'a quiet night was named as a highlight');
    },

    'hockey names the goaltender and only the groups with people in them' => function () use ($read, $espn) {
        $text = $read((new Recap(new Ice()))->document(
            ['home_name' => 'Buffalo Sabres', 'away_name' => 'Dallas Stars',
             'home_score' => 2, 'away_score' => 3],
            $espn('nhl'),
        ));

        assertTrue(str_contains($text, 'Dallas Stars took it by one.'), $text);

        /*
         * 🚨 A whole sentence per case rather than clauses joined with a comma.
         * Built up from parts, the night only the away side converted produced
         * "Dallas Stars once." — the second clause leaning on a first that was
         * never added.
         */
        assertTrue(str_contains($text, 'Dallas Stars scored once on the power play.'), $text);
        assertFalse(str_contains($text, 'Stars once.'), 'the sentence lost its verb');

        assertTrue(str_contains($text, 'Jake Oettinger 21 saves'), $text);
        assertTrue(str_contains($text, 'a goal and an assist'), $text);

        // 🚨 ESPN's fourth hockey group, `skaters`, has labels and no athletes.
        assertFalse(str_contains($text, 'skaters'), 'an empty group reached the prose');
    },

    'an NFL game needs no new words at all' => function () use ($read, $espn) {
        /*
         * 🚨 The seam's best evidence. ESPN's NFL statistic names are IDENTICAL
         * to CollegeFootballData's — `totalYards`, `thirdDownEff`,
         * `possessionTime` — and so are its player labels, so a professional
         * game is described by the college vocabulary that already existed.
         */
        $text = $read((new Recap())->document(
            ['home_name' => 'Green Bay Packers', 'away_name' => 'Washington Commanders',
             'home_score' => 27, 'away_score' => 18],
            $espn('nfl'),
        ));

        assertTrue(str_contains($text, 'Green Bay Packers out-gained Washington Commanders 404 to 230.'), $text);
        assertTrue(str_contains($text, 'Jordan Love 19/31 for 292 and two touchdowns'), $text);
        assertTrue(str_contains($text, 'Tucker Kraft 6 catches for 124 and a touchdown'), $text);
    },

    'an unknown sport falls back rather than throwing' => function () {
        /*
         * A settings value naming a sport that has been removed is somebody's
         * install, not a programming error — and a recap in the wrong
         * vocabulary is a far better outcome than a scheduled job that dies.
         */
        $sports = new Sports();

        assertSame('gridiron', $sports->get('quidditch')->key());
        assertSame('soccer', $sports->get('soccer')->key());
        assertTrue(array_key_exists('gridiron', $sports->choices()));
    },

    'a box score with nothing in it is treated as no box score' => function () {
        $recap = new Recap();

        assertFalse($recap->usable(null));
        assertFalse($recap->usable(['home' => ['stats' => []], 'away' => ['stats' => []]]));
        assertFalse($recap->usable(['home' => ['stats' => ['totalYards' => '1']]]), 'one side is not a box score');
        assertTrue($recap->usable([
            'home' => ['stats' => ['totalYards' => '350']],
            'away' => ['stats' => ['totalYards' => '284']],
        ]));
    },
];
