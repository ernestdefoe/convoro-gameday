<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services;

use Convoro\Engine\Database\Connection;

/**
 * What an operator decides about game threads.
 *
 * 🚨 Off until it is switched on, and it stays off after installing. Something that
 * starts posting threads to a live board the moment it is enabled would be a feature
 * nobody chose.
 */
final class Settings
{
    public function __construct(private Connection $db)
    {
    }

    public function enabled(): bool
    {
        return (string) $this->get('gameday_enabled', '0') === '1';
    }

    /**
     * How long before kickoff a thread opens.
     *
     * Three hours by default: long enough that people arrive to something already
     * there, short enough that the front page is not a wall of tomorrow's games.
     */
    public function leadMinutes(): int
    {
        return max(15, min(2880, (int) $this->get('gameday_lead_minutes', '180')));
    }

    public function recaps(): bool
    {
        return (string) $this->get('gameday_recaps', '1') === '1';
    }

    /**
     * The page shown beside a game thread while it is live, or 0 for none.
     *
     * 🚨 A PAGE id, because that is what core's companion panel is — the panel
     * is a page, and the whole coupling is `topics.live_panel_page_id`. Game
     * Day contributes a scoreboard widget and then gets out of the way; what
     * else goes beside the conversation is the operator's to decide.
     */
    public function panelPageId(): int
    {
        return max(0, (int) $this->get('gameday_panel_page', '0'));
    }

    /** Where a game goes when neither team has a forum — a neutral-site game, say. */
    public function fallbackForumId(): int
    {
        return (int) $this->get('gameday_fallback_forum', '0');
    }

    /**
     * 🚨 Who the thread belongs to. An operator picks a real account rather than
     * this inventing one: a post by a member nobody recognises reads as a bot on a
     * board that has never had one, and a site with a "Scoreboard" account should
     * have made that choice deliberately.
     */
    public function authorId(): int
    {
        return max(1, (int) $this->get('gameday_author', '1'));
    }

    /**
     * 🚨 Deliberately NOT cached, and this replaced a cache that read the whole
     * settings table once per process. That is fine in a web request and wrong in
     * the thing that actually uses this: a queue worker lives for hours, so an
     * operator who changed the fallback forum — or switched the feature off —
     * would go on being ignored until somebody restarted it. A test caught it,
     * because the singleton outlived the test that set the value.
     *
     * The cost is five small indexed reads a minute.
     */
    private function get(string $key, string $fallback): string
    {
        $row = $this->db->table('settings')->where('key', $key)->first();

        return $row === null ? $fallback : (string) $row['value'];
    }
}
