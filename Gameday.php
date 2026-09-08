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

        /*
         * 🚨 Read-only, like `Games`. Picks owns the provider, the API key and
         * the monthly call budget; this side reads what it has synced. A second
         * place that could reach collegefootballdata.com is a second place the
         * budget is not enforced.
         */
        $this->app->singleton('gameday.box_score', fn (): Services\BoxScore => new Services\BoxScore(
            $this->app->make('db'),
        ));

        /*
         * 🚨 Registered as a singleton so an application can add a sport to it
         * before anything asks for one — a league is a class and a line, not a
         * change to `Recap`.
         */
        $this->app->singleton('gameday.sports', fn (): Services\Sports\Sports => new Services\Sports\Sports());

        /*
         * The recap is a function of a game, its box score and the sport's
         * vocabulary — no database and no clock, which is what makes what it
         * writes readable in a test.
         */
        $this->app->singleton('gameday.recap', fn (): Services\Recap => new Services\Recap(
            $this->app->make('gameday.sports')->get($this->app->make('gameday.settings')->sport()),
        ));

        $this->app->singleton('gameday.threads', fn (): Services\Threads => new Services\Threads(
            $this->app->make('db'),
            $this->app->make('gameday.games'),
            $this->app->make('gameday.settings'),
            $this->app->make('gameday.box_score'),
            $this->app->make('gameday.recap'),
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

            /*
             * A BOARD, not a list of two teams.
             *
             * Built from the chrome tokens the site header already uses, which
             * are dark in both themes by design — so this reads as a scoreboard
             * rather than as a card, without a single hardcoded colour and
             * without a light-mode variant to keep in step.
             */
            /*
             * 🚨 Pigskin, and lightened for the ground it sits on. Saddle brown
             * at #8b4513 is the colour of a football and very nearly the colour
             * of this panel, so the ball would read as a hole in the board. A
             * warmer, lighter brown keeps the association and stays visible.
             */
            . '.gd-board{--gd-pigskin:#b0703f;'
            . 'display:flex;flex-direction:column;gap:0.5rem;padding:0.75rem;'
            . 'background:var(--c-chrome-bg);color:var(--c-chrome-ink);'
            . 'border-radius:var(--radius-card);box-shadow:var(--shadow-sm);}'
            . '.gd-strip{display:flex;flex-direction:column;gap:0.35rem;}'
            . '.gd-side{display:flex;align-items:center;gap:0.6rem;}'
            . '.gd-crest{flex:0 0 auto;width:2rem;height:2rem;display:flex;'
            . 'align-items:center;justify-content:center;}'
            . '.gd-crest img{max-width:100%;max-height:100%;}'
            . '.gd-team{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;line-height:1.15;}'
            . '.gd-abbr{font-weight:700;letter-spacing:0.02em;}'

            /* The full name is the detail that gets cut first on a narrow panel. */
            . '.gd-name{font-size:0.75rem;opacity:0.62;overflow:hidden;'
            . 'text-overflow:ellipsis;white-space:nowrap;}'

            /*
             * Tabular figures, so 7 and 21 occupy the same width and the column
             * does not jitter every time somebody scores.
             */
            . '.gd-score{flex:0 0 auto;min-width:1.75rem;text-align:right;'
            . 'font-size:1.5rem;font-weight:700;font-variant-numeric:tabular-nums;}'
            . '.gd-score-none{opacity:0.4;font-weight:500;}'

            . '.gd-status{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;'
            . 'padding-top:0.5rem;border-top:1px solid rgba(255,255,255,0.14);'
            . 'font-size:0.78rem;}'
            . '.gd-live{display:inline-flex;align-items:center;gap:0.35rem;'
            . 'font-weight:700;text-transform:uppercase;letter-spacing:0.06em;}'

            /* The pip is the only thing on the board that moves. */
            . '.gd-pip{width:0.45rem;height:0.45rem;border-radius:50%;'
            . 'background:var(--c-danger-strong,#dc2626);animation:gd-pulse 2s ease-in-out infinite;}'
            . '@keyframes gd-pulse{0%,100%{opacity:1;}50%{opacity:0.25;}}'

            /*
             * 🚨 Respect a reader who has asked for less movement. A pulsing dot
             * is decoration; the word LIVE beside it is the information, and it
             * is still there when the animation is not.
             */
            . '@media (prefers-reduced-motion:reduce){.gd-pip{animation:none;}}'

            /*
             * Beside the name, not in front of it: the abbreviation is what a
             * reader is scanning for, and the ball is the answer to a question
             * they only ask once they have found it.
             */
            . '.gd-ball-off{display:none;}'
            . '.gd-ball{width:1.05rem;height:0.7rem;margin-left:0.4rem;'
            . 'vertical-align:baseline;flex:0 0 auto;}'

            . '.gd-period{opacity:0.85;}'
            . '.gd-down{opacity:0.85;font-variant-numeric:tabular-nums;}'

            /* Inside the twenty. Worth saying, and worth saying in one colour. */
            . '.gd-redzone{color:var(--c-danger-strong,#dc2626);font-weight:700;opacity:1;}'
            . '.gd-clock{margin-left:auto;font-variant-numeric:tabular-nums;opacity:0.85;}'
            . '.gd-final{font-weight:700;text-transform:uppercase;letter-spacing:0.06em;}'
            . '.gd-kickoff{opacity:0.85;}'
            . '.gd-link{font-size:0.8rem;color:inherit;text-decoration:underline;'
            . 'text-underline-offset:2px;opacity:0.85;}'
            . '.gd-link:hover{opacity:1;}'
            . '</style>');

        /*
         * 🚨 What makes the board LIVE rather than a photograph of one.
         *
         * The score changes while somebody is reading the page, and a rendered
         * widget has no way to know. This asks the site what the board says
         * every so often and updates the numbers in place.
         *
         * 🚨 It polls THIS site, not ESPN. The provider is asked on a schedule,
         * by one queue job, at a floor of two minutes — a browser calling out
         * to a public endpoint once per reader per thirty seconds is how a
         * quiet Saturday turns into an address that stops answering this site.
         *
         * It also stops when nobody is looking, and stops for good once the
         * game is final: a page left open overnight should not still be asking
         * at breakfast.
         */
        $this->template()->registerHook('body.end', fn (): string => '<script>'
            . '(function(){'
            . 'var board=document.querySelector("[data-gameday-board]");'
            . 'if(!board||!window.fetch){return;}'
            . 'var every=30000,timer=null;'
            . 'function stop(){if(timer){clearTimeout(timer);timer=null;}}'
            . 'function set(sel,value){var el=board.querySelector(sel);'
            . 'if(el&&value!==null&&value!==undefined&&el.textContent!==String(value)){el.textContent=value;}}'
            . 'function paint(g){'
            . 'if(!g){return false;}'
            . 'var scores=board.querySelectorAll("[data-gameday-score]");'
            /* Away first, then home — the order the strip renders them in. */
            . 'if(scores.length===2){'
            . 'scores[0].textContent=g.away&&g.away.score!==null?g.away.score:"–";'
            . 'scores[1].textContent=g.home&&g.home.score!==null?g.home.score:"–";'
            . '}'
            . 'set("[data-gameday-period]",g.state==="scheduled"?g.kickoff:g.period_line);'
            /*
             * 🚨 The ball MOVES. A refresh that updated the score and left
             * the football on the team that just punted would be worse than not
             * drawing one at all, so it is taken off both sides and put back on
             * whichever the server now says.
             */
            . 'var sides=board.querySelectorAll("[data-gameday-side]");'
            . 'if(sides.length===2){'
            . 'var ball=[g.away&&g.away.has_ball,g.home&&g.home.has_ball];'
            . 'for(var i=0;i<2;i++){var mark=sides[i].querySelector(".gd-ball");'
            . 'if(mark){mark.classList.toggle("gd-ball-off",!ball[i]);}}'
            . '}'
            . 'var down=board.querySelector("[data-gameday-down]");'
            . 'if(down){if(g.down){down.textContent=g.down;down.classList.remove("gd-ball-off");'
            . 'down.classList.toggle("gd-redzone",!!g.red_zone);}else{down.classList.add("gd-ball-off");}}'
            . 'var clock=board.querySelector("[data-gameday-clock]");'
            . 'if(clock){if(g.clock){clock.textContent=g.clock;clock.classList.remove("gd-ball-off");}'
            . 'else{clock.classList.add("gd-ball-off");}}'
            . 'board.className=board.className.replace(/gd-board-[a-z]+/,"gd-board-"+g.state);'
            . 'return g.state==="live";'
            . '}'
            . 'function tick(){'
            . 'if(document.hidden){timer=setTimeout(tick,every);return;}'
            . 'fetch("/gameday/board.json",{headers:{"Accept":"application/json"},credentials:"same-origin"})'
            . '.then(function(r){return r.ok?r.json():null;})'
            . '.then(function(d){'
            . 'var keepGoing=d?paint(d.game):true;'
            . 'if(keepGoing){timer=setTimeout(tick,every);}else{stop();}'
            . '})'
            /* A refresh that fails is not worth telling anybody about; the
               board simply keeps the last thing it knew and tries again. */
            . '.catch(function(){timer=setTimeout(tick,every);});'
            . '}'
            . 'if(board.className.indexOf("gd-board-live")>-1){timer=setTimeout(tick,every);}'
            . 'document.addEventListener("visibilitychange",function(){'
            . 'if(!document.hidden&&!timer&&board.className.indexOf("gd-board-live")>-1){tick();}'
            . '});'
            . '}());'
            . '</script>');

        $this->app->make('widget_types')->register('gameday.scoreboard', [
            'label' => 'gameday.widget_label',
            'group' => 'gameday.name',
            'module' => 'gameday',
            'render' => fn (array $w, ?array $viewer, bool $dark): string => $this->scoreboard(),
        ]);

        /*
         * 🚨 A PAGE BLOCK as well as a widget, and this is what makes the note
         * above true.
         *
         * The comment claimed the scoreboard could be a live topic's companion
         * panel — and a panel is a page, and a page takes BLOCKS, not widgets.
         * Registered only as a widget, it could go in a sidebar and nowhere
         * near the thread it is about: the one place the design note says it
         * belongs. Two registries, because they are two different surfaces.
         */
        $this->app->make('page_block_types')->register('gameday.scoreboard', [
            'label' => 'gameday.widget_label',
            'group' => 'gameday.name',
            /*
             * 🚨 The TOPIC's game, not whatever is on right now.
             *
             * A panel page is shared by every game thread — the pointer is a
             * site setting, so there is no per-thread block to configure. With
             * no topic passed, this rendered `current()` and every thread's
             * companion panel showed the same game: the one that kicked off
             * first. The scoreboard sat beside a thread it was not about.
             */
            'render' => fn (array $settings, string $content): string => $this->scoreboard($this->contextTopicId()),
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
            /*
             * Resolve, then start, then open, then put back anything core's
             * cool-off ended while the game is still being played.
             */
            return $threads->resolve() + $threads->start() + $threads->open() + $threads->sustain();
        });

        $this->schedule()->minutely('gameday.tick');

        /*
         * 🚨 Hourly, and separate from the minutely tick on purpose.
         *
         * A recap is posted the moment a game settles, and the box score does
         * not exist yet — the provider publishes it minutes to hours after the
         * final whistle. So the score goes up at once and this fills the post
         * out when the statistics land, which is a different cadence to
         * everything the tick does and would otherwise run sixty times an hour
         * to do nothing.
         */
        $this->app->make('queue')->handle(
            'gameday.enrich',
            fn (): array => ['enriched' => $this->app->make('gameday.threads')->enrich()],
        );

        $this->schedule()->hourly('gameday.enrich');

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
    /**
     * @param int|null $topicId when given, the game that topic is about; a
     *                          topic with no game falls back to what is on now
     */
    /**
     * The topic this render is happening inside, or null off a topic page.
     * `widgetContext` is the channel the forum already shares for exactly this
     * (it carries the forum id for widget placement rules).
     */
    private function contextTopicId(): ?int
    {
        $context = (array) $this->app->make('template')->shared('widgetContext', []);
        $topicId = (int) ($context['topic_id'] ?? 0);

        return $topicId > 0 ? $topicId : null;
    }

    private function scoreboard(?int $topicId = null): string
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

        $scoreboard = $this->app->make('gameday.scoreboard');

        $game = $topicId !== null && $topicId > 0
            ? $scoreboard->forTopic($topicId, $readable)
            : null;

        // Not a game thread (or the thread has no event yet) — the sidebar
        // behaviour is still the right one.
        $game ??= $scoreboard->current($readable);

        if ($game === null) {
            return '';
        }

        return $this->template()->render('gameday::widgets/scoreboard', [
            'board' => $this->app->make('gameday.scoreboard')->shape($game),
        ]);
    }
}
