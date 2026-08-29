<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Controllers\Front;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;

/**
 * What the scoreboard says right now.
 *
 * 🚨 The same `shape()` the rendered widget uses, so the board a reader arrives
 * at and the board they are looking at a minute later are built from one set of
 * decisions. Two code paths answering the same question is how a refreshed
 * score ends up in a different format from the one it replaced.
 */
final class BoardController extends Controller
{
    public function show(Request $request): Response
    {
        $scoreboard = $this->app->make('gameday.scoreboard');

        /*
         * 🚨 The reader's own readable forums, exactly as the widget resolves
         * them. The score is public; the LINK into the thread is not, if the
         * thread lives in a forum this person cannot open — and an endpoint
         * that forgot that would hand out, to anybody who asked, the existence
         * of every private club thread the scoreboard ever pointed at.
         */
        $game = $scoreboard->current($this->readableForumIds());

        if ($game === null) {
            return $this->json(['game' => null]);
        }

        return $this->json(['game' => $scoreboard->shape($game)]);
    }

    /** @return list<int>|null null means nothing is restricted */
    private function readableForumIds(): ?array
    {
        if (!$this->app->bound('forum.visibility')) {
            return null;
        }

        return $this->app->make('forum.visibility')->readableIds(
            array_map('intval', (array) $this->app->make('template')->shared('viewerGroupIds', []))
        );
    }
}
