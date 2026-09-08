<?php

declare(strict_types=1);

use Convoro\Engine\Convoro;
use Convoro\Extensions\Gameday\Services\Threads;

/*
 * The four moments: a thread opens, goes live at kickoff, resolves at the final
 * whistle, and keeps the score.
 *
 * 🚨 Most of what is asserted here is restraint. It must not open a thread for a
 * game that already finished (installing on a live board mid-season would post two
 * hundred threads in a minute), must not open two for one game however often the
 * tick runs, must not go live before kickoff, and must not announce a score the
 * feed has not confirmed.
 */

$app = Convoro::getInstance();
$db = $app->make('db');

$MARK = 'zz-test-gameday';

$threads = static fn (): Threads => Convoro::getInstance()->make('gameday.threads');

$setting = static function (string $key, string $value) use ($db): void {
    $db->table('settings')->where('key', $key)->deleteAll();
    $db->table('settings')->insertGetId(['key' => $key, 'value' => $value]);
};

$forum = static function (string $name) use ($db, $MARK): int {
    return $db->table('forums')->insertGetId([
        'title' => $MARK . ' ' . $name,
        'slug' => 'zz-test-gameday-' . bin2hex(random_bytes(4)),
        'type' => 'forum',
        'position' => 0,
        'topic_count' => 0,
        'post_count' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
};

/*
 * 🚨 An unmapped team is `forum_id = 0`, not NULL — the column is NOT NULL in Picks.
 * Worth knowing, because "no forum" being a zero rather than an absence is exactly
 * the kind of thing a reader gets wrong once.
 */
$team = static function (string $name, int $forumId = 0) use ($db, $MARK): int {
    return $db->table('picks_teams')->insertGetId([
        'name' => $MARK . ' ' . $name,
        'slug' => 'zz-test-gameday-' . bin2hex(random_bytes(4)),
        'abbreviation' => strtoupper(substr($name, 0, 4)),
        'conference' => 'ZZ',
        'forum_id' => $forumId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
};

$game = static function (int $home, int $away, int $matchAt, array $extra = []) use ($db): int {
    return $db->table('picks_events')->insertGetId(array_merge([
        'home_team_id' => $home,
        'away_team_id' => $away,
        'match_at' => $matchAt,
        'cutoff_at' => $matchAt,
        'status' => 'scheduled',
        'neutral_site' => 0,
        'confirmed_at' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ], $extra));
};

$sweep = static function () use ($db, $MARK): void {
    foreach ($db->table('gameday_threads')->get() as $row) {
        $topic = $db->table('topics')->where('id', (int) $row['topic_id'])->first();

        if ($topic !== null && str_starts_with((string) $topic['title'], $MARK)) {
            $db->table('posts')->where('topic_id', (int) $row['topic_id'])->deleteAll();
            $db->table('topics')->where('id', (int) $row['topic_id'])->deleteAll();
            $db->table('gameday_threads')->where('id', (int) $row['id'])->deleteAll();
        }
    }

    foreach ($db->table('picks_teams')->whereLike('name', $MARK . '%')->get() as $t) {
        $db->table('picks_events')->where('home_team_id', (int) $t['id'])->deleteAll();
        $db->table('picks_events')->where('away_team_id', (int) $t['id'])->deleteAll();
        $db->table('picks_teams')->where('id', (int) $t['id'])->deleteAll();
    }

    foreach ($db->table('forums')->whereLike('title', $MARK . '%')->get() as $f) {
        $db->table('topics')->where('forum_id', (int) $f['id'])->deleteAll();
        $db->table('forums')->where('id', (int) $f['id'])->deleteAll();
    }

    // Leave the operator's own settings alone; only ours are removed.
    foreach (['gameday_enabled', 'gameday_lead_minutes', 'gameday_recaps', 'gameday_fallback_forum', 'gameday_author'] as $key) {
        $db->table('settings')->where('key', $key)->deleteAll();
    }
};

/** The thread row for a game, if it made one. */
$threadFor = static fn (int $eventId): ?array => Convoro::getInstance()->make('db')
    ->table('gameday_threads')->where('event_id', $eventId)->first();

/*
 * 🚨 A fresh instance per assertion, not the container's singleton. Records caches
 * everybody's record for the life of the object — right in a request, wrong in a
 * test run where one container outlives every test in the file.
 */
$records = static fn (): \Convoro\Extensions\Gameday\Services\Records
    => new \Convoro\Extensions\Gameday\Services\Records(Convoro::getInstance()->make('db'));

$score = static function (int $userId, int $seasonId, int $weekId, int $correct, int $total) use ($db): void {
    $db->table('picks_user_scores')->insertGetId([
        'user_id' => $userId, 'season_id' => $seasonId, 'week_id' => $weekId,
        'total_points' => $correct, 'total_picks' => $total, 'correct_picks' => $correct,
        'accuracy' => $total > 0 ? round(($correct / $total) * 100) : 0,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
};

return [
    /*
     * 🚨 The recap gains its statistics without a second post.
     *
     * This exists because the first version of `enrich()` called a method on
     * `Games` that does not exist — `byId()` rather than `find()` — which every
     * other test passed straight over, because nothing exercised the hourly
     * pass. It was found by running it against live data on fbsfb.com, where it
     * was a fatal error in a scheduled job: the recap would have stayed the
     * score for ever and nothing would have said why.
     *
     * So the assertion is not really about the words. It is that this path RUNS.
     */
    'a recap gains its statistics in place when the box score arrives' => function () use (
        $threads, $db, $team, $game, $forum, $setting, $sweep
    ): void {
        try {
            $setting('gameday_enabled', '1');
            $setting('gameday_recaps', '1');

            $forumId = $forum('Games');
            $home = $team('Home', $forumId);
            $away = $team('Away');

            /*
             * The real lifecycle, in order. A game that is already finished
             * never gets a thread opened — deliberately, or installing this
             * mid-season would post one for every game ever played — so the
             * test has to play it forwards like the schedule does.
             */
            $id = $game($home, $away, time() + 600);

            $threads()->open();

            $db->table('picks_events')->where('id', $id)->updateAll([
                'match_at' => time() - 7200,
                'status' => 'finished',
                'home_score' => 41,
                'away_score' => 13,
                'result' => 'home',
                'confirmed_at' => time() - 3600,
            ]);

            $threads()->start();
            $threads()->resolve();

            $row = $db->table('gameday_threads')->where('event_id', $id)->first();

            assertTrue($row !== null, 'the game should have a thread');
            assertTrue($row['recap_post_id'] !== null, 'and a recap');
            assertTrue($row['stats_at'] === null, 'which has no statistics in it yet');

            $before = $db->table('posts')->where('id', (int) $row['recap_post_id'])->first();
            assertFalse(str_contains((string) $before['content_plain'], 'out-gained'));

            $posts = (int) $db->table('posts')->where('topic_id', (int) $row['topic_id'])->count();

            // Now the provider catches up.
            $db->table('picks_box_scores')->insertGetId([
                'event_id' => $id,
                'payload' => json_encode([
                    'game' => 1,
                    'home' => ['team' => 'Home', 'points' => 41,
                        'stats' => ['totalYards' => '350', 'turnovers' => '0'], 'leaders' => []],
                    'away' => ['team' => 'Away', 'points' => 13,
                        'stats' => ['totalYards' => '284', 'turnovers' => '2'], 'leaders' => []],
                ]),
                'fetched_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            assertSame(1, $threads()->enrich(), 'the pass should rewrite exactly one recap');

            $after = $db->table('posts')->where('id', (int) $row['recap_post_id'])->first();

            assertTrue(str_contains((string) $after['content_plain'], 'out-gained'), (string) $after['content_plain']);

            // 🚨 Rewritten IN PLACE. A second post would be noise, and it is the
            // difference between filling a recap out and starting another one.
            assertSame($posts, (int) $db->table('posts')->where('topic_id', (int) $row['topic_id'])->count());

            $done = $db->table('gameday_threads')->where('event_id', $id)->first();
            assertTrue($done['stats_at'] !== null, 'and it is not looked at again');
            assertSame(0, $threads()->enrich());

            $db->table('picks_box_scores')->where('event_id', $id)->deleteAll();
        } finally {
            $sweep();
        }
    },

    'a member with no picks wears no record at all' => static function () use ($records): void {
        /*
         * 🚨 Null, not `0–0`. A zero record is a claim about how somebody is doing;
         * no record is the truth. A board where every lurker wears an 0–0 has made
         * its own feature look broken.
         */
        assertSame(null, $records()->forUser(999999));
    },

    'a record is the whole season, not the last week' => static function () use ($records, $score, $db): void {
        /*
         * 🚨 `picks_user_scores` is per member PER WEEK. Reading one row shows last
         * Saturday as though it were the season.
         */
        try {
            $user = $db->table('users')->insertGetId([
                'username' => 'zz-test-gameday picker', 'username_clean' => 'zz-test-gameday picker',
                'email' => 'zz-test-gameday-picker@invalid', 'password' => '',
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $season = 4242;
            $score($user, $season, 1, 7, 10);
            $score($user, $season, 2, 5, 6);

            // 12 right out of 16 is 12–4.
            assertSame('12–4', $records()->forUser($user));
        } finally {
            foreach ($db->table('users')->whereLike('username', 'zz-test-gameday%')->get() as $u) {
                $db->table('picks_user_scores')->where('user_id', (int) $u['id'])->deleteAll();
                $db->table('users')->where('id', (int) $u['id'])->deleteAll();
            }
        }
    },

    'the scoreboard shows the live game, and drops the link a reader cannot follow' => static function () use ($threads, $setting, $forum, $team, $game, $threadFor, $sweep, $db): void {
        try {
            $setting('gameday_enabled', '1');
            $f = $forum('Auburn');
            $id = $game($team('Auburn', $f), $team('Alabama', $f), time() + 600);

            $threads()->open();
            $db->table('picks_events')->where('id', $id)->updateAll([
                'match_at' => time() - 600, 'home_score' => 17, 'away_score' => 14,
            ]);
            $threads()->start();

            $board = new \Convoro\Extensions\Gameday\Services\Scoreboard($db);

            // Nothing is restricted: the link is there.
            $open = $board->current(null);
            assertTrue($open !== null);
            assertSame('live', (string) $open['state'], 'the live game wins over the next kickoff');
            assertSame(17, (int) $open['home_score']);
            assertTrue((int) $open['topic_id'] > 0);

            /*
             * 🚨 A reader who cannot open that forum still gets the score — it is
             * public — but not a link into a forum they may not read, which is a
             * dead end and a disclosure at once.
             */
            $closed = $board->current([$f + 99999]);
            assertTrue($closed !== null, 'the score is still shown');
            assertSame(null, $closed['topic_id'], 'and the link is gone');
        } finally {
            $sweep();
        }
    },

    'a thread opens before kickoff, in the home team&#039;s forum' => static function () use ($threads, $setting, $forum, $team, $game, $threadFor, $sweep, $db): void {
        try {
            $setting('gameday_enabled', '1');
            $home = $forum('Auburn');
            $away = $forum('Alabama');

            $id = $game($team('Auburn', $home), $team('Alabama', $away), time() + 3600);

            assertSame(1, $threads()->open());

            $row = $threadFor($id);
            assertTrue($row !== null);

            $topic = $db->table('topics')->where('id', (int) $row['topic_id'])->first();
            assertSame($home, (int) $topic['forum_id'], 'the home team hosts it');

            // "Alabama at Auburn" — away team first, as anybody would say it.
            assertTrue(str_contains((string) $topic['title'], ' at '), (string) $topic['title']);
            assertSame(1, (int) $topic['post_count'], 'and it opens with one post');
        } finally {
            $sweep();
        }
    },

    'a game that has already kicked off gets no thread' => static function () use ($threads, $setting, $forum, $team, $game, $threadFor, $sweep): void {
        /*
         * 🚨 The install guard. Without the lower bound, enabling this mid-season
         * posts every game of the year to a live board inside one minute.
         */
        try {
            $setting('gameday_enabled', '1');
            $f = $forum('Auburn');
            $id = $game($team('Auburn', $f), $team('Alabama', $f), time() - 7200);

            assertSame(0, $threads()->open());
            assertSame(null, $threadFor($id));
        } finally {
            $sweep();
        }
    },

    'a game gets one thread however many ticks run' => static function () use ($threads, $setting, $forum, $team, $game, $sweep, $db): void {
        try {
            $setting('gameday_enabled', '1');
            $f = $forum('Auburn');
            $game($team('Auburn', $f), $team('Alabama', $f), time() + 3600);

            assertSame(1, $threads()->open());
            assertSame(0, $threads()->open(), 'the second tick finds nothing to do');
            assertSame(0, $threads()->open());

            assertSame(1, count($db->table('gameday_threads')->get()));
        } finally {
            $sweep();
        }
    },

    'it goes live at kickoff, and not before' => static function () use ($threads, $setting, $forum, $team, $game, $threadFor, $sweep, $db): void {
        try {
            $setting('gameday_enabled', '1');
            $f = $forum('Auburn');
            $id = $game($team('Auburn', $f), $team('Alabama', $f), time() + 3600);

            $threads()->open();
            assertSame(0, $threads()->start(), 'an hour out, it stays an ordinary topic');

            // Kickoff.
            $db->table('picks_events')->where('id', $id)->updateAll(['match_at' => time() - 60]);

            assertSame(1, $threads()->start());

            $row = $threadFor($id);
            assertSame('live', (string) $row['state']);

            $topic = $db->table('topics')->where('id', (int) $row['topic_id'])->first();
            assertSame('live', (string) $topic['live_state'], 'through the Live service, so the panel and slow mode work');
        } finally {
            $sweep();
        }
    },

    'a score is not announced until the feed confirms it' => static function () use ($threads, $setting, $forum, $team, $game, $threadFor, $sweep, $db): void {
        /*
         * 🚨 A board that declares the wrong final score is worse than one that is
         * ten minutes late. `confirmed_at`, not `status`.
         */
        try {
            $setting('gameday_enabled', '1');
            $f = $forum('Auburn');
            $id = $game($team('Auburn', $f), $team('Alabama', $f), time() - 3600);

            // Give it a thread the way a real one would have got it.
            $db->table('picks_events')->where('id', $id)->updateAll(['match_at' => time() + 600]);
            $threads()->open();
            $db->table('picks_events')->where('id', $id)->updateAll(['match_at' => time() - 3600]);
            $threads()->start();

            // Scores in, but unconfirmed.
            $db->table('picks_events')->where('id', $id)->updateAll([
                'status' => 'finished', 'home_score' => 24, 'away_score' => 21, 'confirmed_at' => 0,
            ]);

            /*
             * 🚨 A SCORE IS NOT A RESULT.
             *
             * This is the one that got out. A game in progress, with a
             * confirmed score, must stay live: the condition used to ask only
             * for two scores and a confirmation, which is the same sentence as
             * "the game is over" only if a score never arrives mid-game.
             *
             * On FBSFB the first live score ever recorded was 10-10 in the
             * second quarter, and every gameday thread with a score was
             * resolved seconds later with a recap announcing a tie.
             */
            $db->table('picks_events')->where('id', $id)->updateAll([
                'status' => 'in_progress',
                'home_score' => 10,
                'away_score' => 10,
                'confirmed_at' => time(),
            ]);

            assertSame(0, $threads()->resolve(), 'a score in the second quarter does not end the game');

            $stillLive = $threadFor($id);
            assertSame('live', (string) $stillLive['state'], 'and the thread is still live');

            // Back to the case the rest of this test is about.
            $db->table('picks_events')->where('id', $id)->updateAll([
                'status' => 'finished', 'home_score' => 24, 'away_score' => 21, 'confirmed_at' => 0,
            ]);

            assertSame(0, $threads()->resolve(), 'final on the feed is not the same as settled');

            $db->table('picks_events')->where('id', $id)->updateAll(['confirmed_at' => time()]);

            assertSame(1, $threads()->resolve());

            $row = $threadFor($id);
            assertSame('resolved', (string) $row['state']);

            $topic = $db->table('topics')->where('id', (int) $row['topic_id'])->first();
            assertSame('resolved', (string) $topic['live_state'], 'and it is an ordinary topic again');
        } finally {
            $sweep();
        }
    },

    'the recap is a new post and the opening post is untouched' => static function () use ($threads, $setting, $forum, $team, $game, $threadFor, $sweep, $db): void {
        try {
            $setting('gameday_enabled', '1');
            $f = $forum('Auburn');
            $id = $game($team('Auburn', $f), $team('Alabama', $f), time() + 600);

            $threads()->open();
            $row = $threadFor($id);
            $opener = $db->table('posts')->where('topic_id', (int) $row['topic_id'])->first();
            $openerBefore = (string) $opener['content_html'];

            $db->table('picks_events')->where('id', $id)->updateAll([
                'match_at' => time() - 3600, 'status' => 'finished',
                'home_score' => 31, 'away_score' => 28, 'confirmed_at' => time(),
            ]);

            $threads()->start();
            $threads()->resolve();

            $posts = $db->table('posts')->where('topic_id', (int) $row['topic_id'])->get();
            assertSame(2, count($posts), 'the recap is an addition, not an edit');

            $again = $db->table('posts')->where('id', (int) $opener['id'])->first();
            assertSame($openerBefore, (string) $again['content_html'], 'nobody rewrote the opening post');

            $recap = (string) $posts[1]['content_html'];
            assertTrue(str_contains($recap, '31'), 'and it carries the score');
            assertTrue(str_contains($recap, '28'));

            $fresh = $threadFor($id);
            assertTrue((int) $fresh['recap_post_id'] > 0, 'recorded, so a second tick adds nothing');

            assertSame(0, $threads()->resolve());
            assertSame(2, count($db->table('posts')->where('topic_id', (int) $row['topic_id'])->get()));
        } finally {
            $sweep();
        }
    },

    'a neutral-site game falls back to the forum the operator named' => static function () use ($threads, $setting, $forum, $team, $game, $threadFor, $sweep, $db): void {
        try {
            $setting('gameday_enabled', '1');
            $neutral = $forum('Bowls');
            $setting('gameday_fallback_forum', (string) $neutral);

            $id = $game($team('Auburn', 0), $team('Alabama', 0), time() + 3600, ['neutral_site' => 1]);

            assertSame(1, $threads()->open());

            $row = $threadFor($id);
            $topic = $db->table('topics')->where('id', (int) $row['topic_id'])->first();

            assertSame($neutral, (int) $topic['forum_id']);
            assertTrue(str_contains((string) $topic['title'], ' vs '), 'neutral games are "vs", not "at"');
        } finally {
            $sweep();
        }
    },

    'a game with nowhere to go is skipped rather than failing' => static function () use ($threads, $setting, $team, $game, $threadFor, $sweep): void {
        /*
         * 🚨 A board part-way through mapping its teams should get the games it can,
         * not a scheduled job that throws every minute.
         */
        try {
            $setting('gameday_enabled', '1');
            $id = $game($team('Auburn', 0), $team('Alabama', 0), time() + 3600);

            assertSame(0, $threads()->open());
            assertSame(null, $threadFor($id));
        } finally {
            $sweep();
        }
    },

    'the scoreboard is registered where a panel can actually use it' => static function (): void {
        /*
         * 🚨 Two registries, two surfaces. A panel is a PAGE and a page takes
         * BLOCKS; `widget_types` only gets it into a sidebar. Registered as a
         * widget alone, the scoreboard could go anywhere except the one place
         * the design note says it belongs — beside the game it is about.
         */
        $app = Convoro::getInstance();

        assertTrue($app->make('widget_types')->has('gameday.scoreboard'), 'a widget');
        assertTrue($app->make('page_block_types')->has('gameday.scoreboard'), 'and a page block');
    },

    'a game thread carries the panel from the moment it opens' => static function () use ($threads, $setting, $team, $game, $threadFor, $sweep, $db, $forum): void {
        /*
         * 🚨 Set at CREATION, not when the thread goes live. `Live::start()`
         * does not take a panel, and one added afterwards would be missing for
         * exactly the minutes people arrive — the only minutes a scoreboard
         * beside a thread is worth having.
         */
        try {
            $setting('gameday_enabled', '1');
            $setting('gameday_panel_page', '4242');

            $id = $game($team('Auburn', $forum('panel')), $team('Alabama', 0), time() + 1800);

            assertSame(1, $threads()->open());

            $row = $threadFor($id);
            $topic = $db->table('topics')->where('id', (int) $row['topic_id'])->first();

            assertSame(4242, (int) $topic['live_panel_page_id']);
        } finally {
            $setting('gameday_panel_page', '0');
            $sweep();
        }
    },

    'no panel chosen leaves the thread exactly as it was' => static function () use ($threads, $setting, $team, $game, $threadFor, $sweep, $db, $forum): void {
        try {
            $setting('gameday_enabled', '1');
            $setting('gameday_panel_page', '0');

            $id = $game($team('Auburn', $forum('nopanel')), $team('Alabama', 0), time() + 1800);

            assertSame(1, $threads()->open());

            $topic = $db->table('topics')->where('id', (int) $threadFor($id)['topic_id'])->first();

            assertSame(null, $topic['live_panel_page_id']);
        } finally {
            $sweep();
        }
    },

    'the author is chosen by name, and a name nobody has changes nothing' => static function (): void {
        /*
         * 🚨 The failure this guards is public and embarrassing: a typo in the
         * author field silently falling back to member 1 means Saturday's game
         * threads are posted under whoever happens to be the site's first
         * account. Everything else on the form still saves — an operator who
         * fixed the lead time and mistyped a name should not lose both.
         */
        $source = (string) file_get_contents(dirname(__DIR__) . '/Controllers/Admin/GamedayController.php');

        assertTrue(str_contains($source, "where('username_clean'"), 'looked up by name');
        assertFalse(
            str_contains($source, "'gameday_author' => (string) max(1,"),
            'the raw-id fallback to member 1 must be gone'
        );
        assertTrue(str_contains($source, 'author_unknown'), 'and an unknown name is reported');
    },

    'the manifest admits it cannot work without Picks' => static function (): void {
        /*
         * 🚨 A manifest floor is a PROMISE, and the LOOSE direction is the one
         * that hurts: an extension that installs cleanly and then throws on
         * every page is worse than one that refuses to install.
         *
         * `Scoreboard` joins `picks_events` and `picks_teams` with no guard —
         * deliberately, because a scoreboard with no fixtures is not a
         * degraded scoreboard, it is nothing. So the dependency belongs here,
         * where the installer can enforce it, rather than in a runtime check
         * that hides the problem.
         */
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/manifest.json'),
            true
        );

        assertTrue(in_array('picks', $manifest['requires'], true), 'Picks is required, not optional');
    },
];
