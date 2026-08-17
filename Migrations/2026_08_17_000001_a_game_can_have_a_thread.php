<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;
use Convoro\Engine\Database\Schema\Blueprint;

/**
 * One row per game whose thread exists.
 *
 * 🚨 A map rather than a column on `picks_events`, because Picks owns that table and
 * an extension writing into another extension's schema is how two features end up
 * unable to be uninstalled independently. This table can be dropped and Picks does
 * not notice.
 *
 * Reasoning: convoro2/docs/design/2026-08-17-game-day.md.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema->create('gameday_threads', function (Blueprint $bp): void {
            $bp->id();

            /*
             * 🚨 UNIQUE, and that is the whole idempotency story. The tick runs every
             * minute and may overlap itself; a check-then-insert would open a second
             * thread the first time it did. No foreign key to `picks_events`, for the
             * reason in the class note above.
             */
            $bp->bigInt('event_id', true);
            $bp->bigInt('topic_id', true);

            // 'open' before kickoff, 'live' during, 'resolved' after the recap.
            $bp->string('state', 20)->default('open');

            /*
             * The recap, so a second final-whistle tick adds nothing. Null until the
             * game ends; a game that ends while the site is down still gets one, on
             * the next tick, which is why this is a column rather than an assumption
             * about timing.
             */
            $bp->bigInt('recap_post_id', true)->nullable();

            $bp->datetime('opened_at')->nullable();
            $bp->datetime('live_at')->nullable();
            $bp->datetime('resolved_at')->nullable();
            $bp->timestamps();

            $bp->unique('event_id', 'gameday_threads_event');
            $bp->index('state');
        });
    }

    public function down(): void
    {
        $this->schema->drop('gameday_threads');
    }
};
