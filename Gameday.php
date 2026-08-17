<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday;

use Convoro\Engine\Module\Module;

/**
 * Game Day.
 *
 * A thread for every game: opened before kickoff, live while it is played, and kept
 * afterwards with the score on the end of it.
 *
 * 🚨 It reads the fixtures Picks already syncs and reaches nothing itself.
 * CollegeFootballData allows a thousand calls a calendar MONTH on the free tier, so
 * anything here that fetched would spend the month in an afternoon and take Picks
 * with it. Reasoning: convoro2/docs/design/2026-08-17-game-day.md.
 */
final class Gameday extends Module
{
    public function register(): void
    {
        /*
         * Under Community: this is about what the board does on a Saturday, not
         * about how the server is configured.
         */
        $this->adminNav()->area('community')
            ->item('gameday', '/admin/gameday', 'gameday.nav');

        $this->app->singleton('gameday.settings', fn (): Services\Settings => new Services\Settings(
            $this->app->make('db'),
        ));

        $this->app->singleton('gameday.games', fn (): Services\Games => new Services\Games(
            $this->app->make('db'),
        ));

        $this->app->singleton('gameday.scoreboard', fn (): Services\Scoreboard => new Services\Scoreboard(
            $this->app->make('db'),
        ));

        $this->app->singleton('gameday.records', fn (): Services\Records => new Services\Records(
            $this->app->make('db'),
        ));

        $this->app->singleton('gameday.threads', fn (): Services\Threads => new Services\Threads(
            $this->app->make('db'),
            $this->app->make('gameday.games'),
            $this->app->make('gameday.settings'),
        ));
    }

    public function boot(): void
    {
        /*
         * A placeable block, so the same scoreboard can be the live topic's
         * companion panel, a sidebar widget, or a row on the front page. A panel is
         * a page and a page takes widgets, so nothing special is needed to put it
         * beside a game.
         */
        /*
         * 🚨 The extension ships its own CSS through `head.after`, which is the
         * pattern every port follows: layout only, and the theme still owns colour
         * and type through its tokens. A raw hex here would be wrong in one of the
         * two themes, and editing the theme's stylesheet would be a fork.
         */
        $this->template()->registerHook('head.after', fn (): string => '<style>'
            . '.gameday-record{margin-left:0.25rem;padding:0 0.25rem;border-radius:var(--radius-pill);'
            . 'background:var(--c-hover);color:var(--c-text-muted);font-size:0.7rem;'
            . 'font-variant-numeric:tabular-nums;}'
            . '.gameday-scoreboard{display:flex;flex-direction:column;gap:0.25rem;}'
            . '.gameday-teams{display:flex;justify-content:space-between;gap:0.5rem;}'
            . '.gameday-team{font-weight:600;}'
            . '.gameday-score{font-variant-numeric:tabular-nums;font-weight:600;}'
            . '.gameday-when{margin:0.25rem 0 0;color:var(--c-text-muted);font-size:0.8rem;}'
            . '.gameday-link{font-size:0.8rem;}'
            . '</style>');

        $this->app->make('widget_types')->register('gameday.scoreboard', [
            'label' => __('gameday.widget_label'),
            'group' => __('gameday.name'),
            'module' => 'gameday',
            'render' => fn (array $w, ?array $viewer, bool $dark): string => $this->scoreboard(),
        ]);

        /*
         * 🚨 Queued and minutely, never in a request. Kickoff is a moment, so a
         * thread that opened on the next page view would open when somebody happened
         * to arrive — which on a quiet Tuesday is hours late and on a Saturday is a
         * visitor paying for everybody else's threads.
         */
        $this->app->make('queue')->handle('gameday.tick', function (): int {
            $settings = $this->app->make('gameday.settings');

            if (!$settings->enabled() || !$this->app->make('gameday.games')->available()) {
                return 0;
            }

            $threads = $this->app->make('gameday.threads');

            /*
             * Ordered: resolve first, then start, then open. A game that finished
             * while the site was down should be tidied before the next one is
             * opened, and doing it in this order means one tick can carry a thread
             * all the way through if it has to.
             */
            return $threads->resolve() + $threads->start() + $threads->open();
        });

        $this->schedule()->minutely('gameday.tick');

        /*
         * The record beside the name.
         *
         * 🚨 Rendered through the `post.header` hook rather than by editing the post
         * partial, which is the difference between an extension and a fork — an
         * upgrade cannot undo it.
         *
         * 🚨 It renders NOTHING for somebody with no picks. A board where every
         * lurker wears an 0–0 has made its own feature look broken.
         */
        $this->template()->registerHook('post.header', function (array $context): string {
            $userId = (int) ($context['post']['user_id'] ?? 0);

            if ($userId < 1) {
                return '';
            }

            $record = $this->app->make('gameday.records')->forUser($userId);

            if ($record === null) {
                return '';
            }

            return '<span class="gameday-record" title="'
                . htmlspecialchars(__('gameday.record_title'), ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($record, ENT_QUOTES, 'UTF-8')
                . '</span>';
        });
    }

    /**
     * 🚨 Renders nothing at all when there is no game. An empty scoreboard is worse
     * than no scoreboard: it takes permanent space to say nothing, and on a Tuesday
     * in June that is every page view.
     */
    private function scoreboard(): string
    {
        if (!$this->app->make('gameday.games')->available()) {
            return '';
        }

        /*
         * 🚨 The viewer's own readable forums, resolved here and handed down. The
         * score is public; the LINK into the thread is not, if the thread lives in a
         * forum they cannot open.
         */
        $readable = $this->app->make('forum.visibility')->readableIds(
            array_map('intval', (array) $this->app->make('template')->shared('viewerGroupIds', []))
        );

        $game = $this->app->make('gameday.scoreboard')->current($readable);

        if ($game === null) {
            return '';
        }

        return $this->template()->render('gameday::widgets/scoreboard', ['game' => $game]);
    }
}
