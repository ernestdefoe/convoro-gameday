<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Controllers\Admin;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;

/**
 * Admin → Game Day.
 *
 * 🚨 The screen leads with the NEXT FEW GAMES and whether each has a thread, not
 * with the settings. An operator's question is never "what is the lead time", it is
 * "is Saturday covered" — and a settings form cannot answer that. It is also the
 * only place the effect of the fallback forum is visible before a Saturday proves
 * it wrong.
 */
final class GamedayController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = $this->app->make('gameday.settings');
        $games = $this->app->make('gameday.games');

        return $this->render('gameday::admin/index', [
            'available' => $games->available(),
            'enabled' => $settings->enabled(),
            'lead' => $settings->leadMinutes(),
            'recaps' => $settings->recaps(),
            /*
             * 🚨 The options come from the REGISTRY, never from a list written
             * into the template. A second copy of the sport list goes stale the
             * first time an extension registers a league — which is the whole
             * reason the registry exists.
             */
            'sport' => $settings->sport(),
            'sports' => $this->app->make('gameday.sports')->choices(),
            'fallback' => $settings->fallbackForumId(),
            /*
             * 🚨 Shown as a NAME, stored as an id.
             *
             * The field used to be the raw number, which asks an operator to go
             * and look up a member id to answer "who posts these" — a question
             * they can answer instantly in words. The id is what gets stored,
             * because a member who changes their name must not silently detach
             * the setting.
             */
            'author' => $settings->authorId(),
            'authorName' => (string) ($this->app->make('db')->table('users')
                ->where('id', $settings->authorId())
                ->first()['username'] ?? ''),
            'forums' => $this->app->make('db')->table('forums')->orderBy('title')->limit(500)->get(),

            /*
             * The same list a moderator gets when attaching a panel by hand,
             * from core's own service — published pages only, so nobody can
             * pick a draft and then wonder why Saturday's threads have an
             * empty panel.
             */
            'panel' => $settings->panelPageId(),
            'panels' => $this->app->make('forum.live_panel')->choices(),
            'upcoming' => $games->available() ? $this->upcoming() : [],
            // `getFlash`, not `pull` — the latter is a method I assumed and Convoro does not have.
            'notice' => $this->session($request)->getFlash('gameday_notice'),
        ]);
    }

    public function save(Request $request): Response
    {
        $db = $this->app->make('db');

        $values = [
            'gameday_enabled' => $request->input('enabled') !== null ? '1' : '0',
            'gameday_recaps' => $request->input('recaps') !== null ? '1' : '0',
            /*
             * 🚨 Validated against the registry rather than stored as typed. An
             * unknown key would fall back to gridiron every time it is read,
             * which looks exactly like the setting not saving.
             */
            'gameday_sport' => $this->app->make('gameday.sports')->has((string) $request->input('sport'))
                ? (string) $request->input('sport')
                : \Convoro\Extensions\Gameday\Services\Sports\Sports::DEFAULT,
            /*
             * Clamped here as well as in Settings. The form is one way in; a value
             * typed into the database by hand is another, and neither should be able
             * to open every thread of the season at once.
             */
            'gameday_lead_minutes' => (string) max(15, min(2880, (int) $request->input('lead', '180'))),
            'gameday_fallback_forum' => (string) max(0, (int) $request->input('fallback', '0')),
            'gameday_panel_page' => (string) max(0, (int) $request->input('panel', '0')),
        ];

        /*
         * 🚨 An unknown name LEAVES THE SETTING ALONE and says so, rather than
         * falling back to member 1. Silently posting game threads as whoever
         * happens to be the first account on the site is the kind of default
         * that gets noticed on a Saturday, in public, under somebody's name.
         */
        $name = trim((string) $request->input('author', ''));
        $problem = null;

        if ($name !== '') {
            $author = $db->table('users')
                ->where('username_clean', mb_strtolower($name))
                ->whereNull('deleted_at')
                ->first();

            $author === null
                ? $problem = __('gameday.author_unknown', ['name' => $name])
                : $values['gameday_author'] = (string) (int) $author['id'];
        }

        foreach ($values as $key => $value) {
            $db->table('settings')->where('key', $key)->deleteAll();
            $db->table('settings')->insertGetId(['key' => $key, 'value' => $value]);
        }

        $this->session($request)->flash('gameday_notice', $problem ?? __('gameday.saved'));

        return $this->redirect('/admin/gameday');
    }

    /**
     * The next handful of fixtures, with the thread each one has or has not got.
     *
     * @return list<array<string, mixed>>
     */
    private function upcoming(): array
    {
        $db = $this->app->make('db');
        $events = $db->prefixed('picks_events');
        $teams = $db->prefixed('picks_teams');
        $threads = $db->prefixed('gameday_threads');
        $topics = $db->prefixed('topics');

        return $db->select(
            "SELECT e.`id`, e.`match_at`, e.`neutral_site`,
                    h.`name` AS home_name, a.`name` AS away_name,
                    t.`state`, t.`topic_id`, tp.`slug` AS topic_slug
               FROM `{$events}` e
               INNER JOIN `{$teams}` h ON h.`id` = e.`home_team_id`
               INNER JOIN `{$teams}` a ON a.`id` = e.`away_team_id`
               LEFT JOIN `{$threads}` t ON t.`event_id` = e.`id`
               LEFT JOIN `{$topics}` tp ON tp.`id` = t.`topic_id`
              WHERE e.`match_at` > ?
              ORDER BY e.`match_at` ASC
              LIMIT 12",
            [time() - 86400]
        );
    }
}
