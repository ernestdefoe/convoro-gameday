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
    public function __construct(
        private Connection $db,
        private Games $games,
        private Settings $settings,
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

            $this->db->table('gameday_threads')
                ->where('event_id', (int) $game['id'])
                ->updateAll([
                    'state' => 'resolved',
                    'recap_post_id' => $postId,
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

            $topicId = $this->db->table('topics')->insertGetId([
                'forum_id' => $forumId,
                'user_id' => $author,
                'title' => $title,
                'slug' => $this->uniqueSlug($title),
                'post_count' => 1,
                'view_count' => 0,
                'last_post_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

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

    /** The score, as a post somebody returning on Sunday actually reads. */
    private function recap(array $game, int $topicId): ?int
    {
        $home = (int) $game['home_score'];
        $away = (int) $game['away_score'];

        $winner = $home === $away
            ? 'It finished level.'
            : ($home > $away
                ? $game['home_name'] . ' won it.'
                : $game['away_name'] . ' won it.');

        $body = $this->document([
            $this->line(sprintf(
                'Final: %s %d, %s %d.',
                (string) $game['home_name'],
                $home,
                (string) $game['away_name'],
                $away
            )),
            $this->line($winner . ' The thread is an ordinary topic again now — still here, still searchable.'),
        ]);

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

    private function kickoffLine(array $game): string
    {
        $at = (int) $game['match_at'];

        return 'Kickoff ' . date('l j F, g:ia', $at) . '.';
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
