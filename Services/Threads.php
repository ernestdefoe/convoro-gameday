<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services;

use Convoro\Engine\Convoro;
use Convoro\Engine\Database\Connection;
use Convoro\Engine\Support\Str;
use Convoro\Engine\Support\TipTapRenderer;

/**
 * Opening, starting and finishing a game's thread.
 *
 * 🚨 A game thread is an ORDINARY TOPIC. Not a content type, not a row with a flag
 * nothing else understands — so it is quotable, searchable, moderatable, and still
 * there if this extension is removed. That is the test of whether a feature owns its
 * content or merely borrows it.
 *
 * Reasoning: convoro2/docs/design/2026-08-17-game-day.md.
 */
final class Threads
{
    /**
     * How long after a game a recap is still worth rewriting.
     *
     * Matches the window Picks keeps trying to fetch a box score in — past it,
     * one has either arrived or is not coming.
     */
    private const STATS_WINDOW_HOURS = 48;

    /** Recaps rewritten in one pass, so an hourly tick cannot run long. */
    private const ENRICH_BATCH = 40;

    public function __construct(
        private Connection $db,
        private Games $games,
        private Settings $settings,
        private BoxScore $boxScore,
        private Recap $recaps,
    ) {
    }

    /** Open threads for games about to kick off. Returns how many were opened. */
    public function open(): int
    {
        $opened = 0;

        foreach ($this->games->dueToOpen($this->settings->leadMinutes()) as $game) {
            $forumId = $this->forumFor($game);

            if ($forumId === null) {
                /*
                 * 🚨 No forum, no thread — and no exception either. A board that has
                 * not finished mapping its teams should get the games it can, not a
                 * failing scheduled job every minute.
                 */
                continue;
            }

            if ($this->createFor($game, $forumId)) {
                $opened++;
            }
        }

        return $opened;
    }

    /** Take kicked-off threads live. Returns how many were started. */
    public function start(): int
    {
        $live = Convoro::getInstance()->make('forum.live');
        $started = 0;

        foreach ($this->games->dueToStart() as $game) {
            $topicId = (int) $game['topic_id'];

            /*
             * 🚨 Through the Live SERVICE, never by writing `live_state`. Going
             * through it is what makes the companion panel, slow mode, presence and
             * the running summary all behave — and it means this file does not have
             * to know how any of them work.
             */
            $live->start($topicId, true);

            $this->db->table('gameday_threads')
                ->where('event_id', (int) $game['id'])
                ->updateAll([
                    'state' => 'live',
                    'live_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            $started++;
        }

        return $started;
    }

    /**
     * Keep a thread live for as long as the game is being played.
     *
     * 🚨 A gameday thread is live because the GAME is on, not because
     * people are talking in it — and core's auto-resolve cannot know that. It
     * ends any live topic that has gone quiet, which is right for a topic that
     * went live by getting hot and wrong for one put live by a kickoff: a
     * thread nobody has posted in yet is the ordinary state of a game thread
     * at 12:04, and at halftime.
     *
     * FBSFB's North Carolina vs TCU thread was resolved twice this way — once
     * seconds after kickoff, and again at halftime, both times while the game
     * was still being played. So each tick puts back what the sweep took.
     *
     * 🚨 Deliberately NOT a change to `Live::cool()`. Cooling an idle live
     * topic is correct and is what stops one sitting live for ever; the thing
     * core is missing is not a weaker rule, it is that something else owns this
     * topic's state. Re-asserting it every minute says exactly that, and says
     * it without weakening the rule for everybody else.
     *
     * @return int how many were put back
     */
    public function sustain(): int
    {
        $live = Convoro::getInstance()->make('forum.live');
        $restored = 0;

        foreach ($this->games->stillPlaying() as $game) {
            $topicId = (int) $game['topic_id'];
            $topic = $this->db->table('topics')->where('id', $topicId)->first();

            if ($topic === null || (string) $topic['live_state'] === 'live') {
                continue;
            }

            /*
             * `false`, so it is never mistaken for a topic that went live by
             * getting hot — that flag is what the front end reads to explain
             * itself to a moderator.
             */
            $live->start($topicId, false);
            $restored++;
        }

        return $restored;
    }

    /** Resolve finished games and leave the score behind. Returns how many. */
    public function resolve(): int
    {
        $live = Convoro::getInstance()->make('forum.live');
        $resolved = 0;

        foreach ($this->games->dueToResolve() as $game) {
            $topicId = (int) $game['topic_id'];

            $live->resolve($topicId);

            $postId = $this->settings->recaps()
                ? $this->recap($game, $topicId)
                : null;

            /*
             * 🚨 `stats_at` is set only when the recap already HAS statistics —
             * which happens when a game settles late enough that Picks got the
             * box score first. Leaving it null the rest of the time is what
             * puts the thread in front of `enrich()`.
             */
            $this->db->table('gameday_threads')
                ->where('event_id', (int) $game['id'])
                ->updateAll([
                    'state' => 'resolved',
                    'recap_post_id' => $postId,
                    'stats_at' => $postId !== null && $this->recaps->usable(
                        $this->boxScore->forEvent((int) $game['id'])
                    ) ? date('Y-m-d H:i:s') : null,
                    'resolved_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            $resolved++;
        }

        return $resolved;
    }

    /* -------------------------------------------------------------- private */

    /**
     * 🚨 The home team's forum, then the away team's, then whatever the operator
     * named. A neutral-site game belongs to neither, which is exactly when the
     * fallback earns its keep.
     */
    private function forumFor(array $game): ?int
    {
        foreach (['home_forum_id', 'away_forum_id'] as $key) {
            $id = (int) ($game[$key] ?? 0);

            if ($id > 0 && $this->forumExists($id)) {
                return $id;
            }
        }

        $fallback = $this->settings->fallbackForumId();

        return $fallback > 0 && $this->forumExists($fallback) ? $fallback : null;
    }

    private function forumExists(int $id): bool
    {
        return $this->db->table('forums')->where('id', $id)->first() !== null;
    }

    private function createFor(array $game, int $forumId): bool
    {
        $title = $this->title($game);
        $now = date('Y-m-d H:i:s');
        $author = $this->settings->authorId();

        $body = $this->document([
            $this->line($this->kickoffLine($game)),
            $this->line('This thread goes live at kickoff and stays here afterwards.'),
        ]);

        $renderer = new TipTapRenderer();
        $html = $renderer->render($body);

        return (bool) $this->db->transaction(function () use ($game, $forumId, $title, $now, $author, $body, $html): bool {
            /*
             * 🚨 The map row is written FIRST and its unique index is what makes this
             * idempotent. If two ticks overlap, the second one's insert fails here —
             * before a topic exists — rather than after it has posted a duplicate.
             */
            try {
                $this->db->table('gameday_threads')->insertGetId([
                    'event_id' => (int) $game['id'],
                    'topic_id' => 0,
                    'state' => 'open',
                    'opened_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable) {
                return false;
            }

            $topic = [
                'forum_id' => $forumId,
                'user_id' => $author,
                'title' => $title,
                'slug' => $this->uniqueSlug($title),
                'post_count' => 1,
                'view_count' => 0,
                'last_post_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            /*
             * 🚨 The panel is set at CREATION, not when the thread goes live.
             *
             * `Live::start()` does not take one, and a panel added afterwards
             * would be missing for exactly the minutes people arrive — which
             * is the only time a scoreboard is worth having beside a thread.
             *
             * Nothing here checks that the page is published: `LivePanel`
             * already renders nothing for a page it would not offer, so a page
             * unpublished later degrades to a thread with no panel rather than
             * to a broken one.
             */
            $panel = $this->settings->panelPageId();

            if ($panel > 0) {
                $topic['live_panel_page_id'] = $panel;
            }

            $topicId = $this->db->table('topics')->insertGetId($topic);

            $postId = $this->db->table('posts')->insertGetId([
                'topic_id' => $topicId,
                'user_id' => $author,
                'content' => json_encode($body),
                'content_html' => $html,
                'content_plain' => trim(strip_tags($html)),
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db->table('topics')->where('id', $topicId)->updateAll([
                'first_post_id' => $postId,
                'last_post_id' => $postId,
                'updated_at' => $now,
            ]);

            $this->db->table('gameday_threads')
                ->where('event_id', (int) $game['id'])
                ->updateAll(['topic_id' => $topicId, 'updated_at' => $now]);

            return true;
        });
    }

    /**
     * Rewrites recaps that were posted before the box score arrived.
     *
     * 🚨 The recap goes up the moment a game settles, when the statistics do
     * not exist yet — the provider publishes them minutes to hours later.
     * Waiting would delay the final score, which is the one thing everybody in
     * the thread is waiting for; a second post would be noise. So the first
     * post is the score and this fills it out in place.
     *
     * 🚨 It gives up after a while. A game the provider never covered would
     * otherwise be re-examined every hour for ever, and a recap that never
     * gains statistics is a perfectly good recap — it is the one every game
     * got before any of this existed.
     *
     * @return int recaps rewritten in this pass
     */
    public function enrich(?int $now = null): int
    {
        if (!$this->settings->recaps() || !$this->boxScore->available()) {
            return 0;
        }

        $now ??= time();
        $rewritten = 0;

        $waiting = $this->db->table('gameday_threads')
            ->where('state', 'resolved')
            ->whereNull('stats_at')
            ->whereNotNull('recap_post_id')
            ->where('resolved_at', '>', date('Y-m-d H:i:s', $now - (self::STATS_WINDOW_HOURS * 3600)))
            ->limit(self::ENRICH_BATCH)
            ->get();

        foreach ($waiting as $row) {
            $eventId = (int) $row['event_id'];
            $box = $this->boxScore->forEvent($eventId);

            if (!$this->recaps->usable($box)) {
                continue;
            }

            $game = $this->games->byId($eventId);

            if ($game === null) {
                continue;
            }

            $this->rewrite((int) $row['recap_post_id'], $this->recaps->document($game, $box));

            $this->db->table('gameday_threads')->where('id', (int) $row['id'])->updateAll([
                'stats_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $rewritten++;
        }

        return $rewritten;
    }

    /** Replaces a post's body, leaving everything else about it alone. */
    private function rewrite(int $postId, array $body): void
    {
        $html = (new TipTapRenderer())->render($body);

        $this->db->table('posts')->where('id', $postId)->updateAll([
            'content' => json_encode($body),
            'content_html' => $html,
            'content_plain' => trim(strip_tags($html)),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** The score, as a post somebody returning on Sunday actually reads. */
    private function recap(array $game, int $topicId): ?int
    {
        $body = $this->recaps->document($game, $this->boxScore->forEvent((int) $game['id']));

        $renderer = new TipTapRenderer();
        $html = $renderer->render($body);
        $now = date('Y-m-d H:i:s');

        $position = (int) $this->db->table('posts')->where('topic_id', $topicId)->count() + 1;

        $postId = $this->db->table('posts')->insertGetId([
            'topic_id' => $topicId,
            'user_id' => $this->settings->authorId(),
            'content' => json_encode($body),
            'content_html' => $html,
            'content_plain' => trim(strip_tags($html)),
            'position' => $position,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db->table('topics')->where('id', $topicId)->updateAll([
            'post_count' => $position,
            'last_post_id' => $postId,
            'last_post_at' => $now,
            'updated_at' => $now,
        ]);

        return $postId;
    }

    private function title(array $game): string
    {
        $joiner = (int) ($game['neutral_site'] ?? 0) === 1 ? ' vs ' : ' at ';

        // Away team first: "Alabama at Auburn" is how anybody would say it.
        return trim((string) $game['away_name']) . $joiner . trim((string) $game['home_name']);
    }

    /**
     * 🚨 The site's timezone, named in the line itself.
     *
     * This is WRITTEN INTO a post rather than rendered, so there is no reader
     * to ask and it cannot be per-viewer: whatever goes in here is what
     * everybody sees for good. `date()` put it in the process timezone, which
     * is UTC on any install that has not changed it — so a noon kickoff in
     * Chapel Hill was posted as "4:00pm" and stayed wrong in the archive.
     *
     * The zone is printed alongside the time because a bare "12:00pm" in a
     * post read from another country says nothing.
     */
    private function kickoffLine(array $game): string
    {
        $at = (int) $game['match_at'];
        $zone = \Convoro\Engine\Http\Middleware\ApplyTimezone::site();

        return 'Kickoff ' . \Convoro\Engine\Support\Presence::inZone($zone, 'l j F, g:ia T', $at) . '.';
    }

    /** @param list<array<string, mixed>> $paragraphs */
    private function document(array $paragraphs): array
    {
        return ['type' => 'doc', 'content' => $paragraphs];
    }

    private function line(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function uniqueSlug(string $from): string
    {
        $base = Str::slug($from) ?: 'game';
        $slug = $base;
        $n = 2;

        while ($this->db->table('topics')->where('slug', $slug)->first() !== null) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
