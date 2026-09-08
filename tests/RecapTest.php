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
        assertTrue(str_contains($say(21, 21), 'It finished level.'));
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
