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
}
