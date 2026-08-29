<?php

declare(strict_types=1);

use Convoro\Extensions\Gameday\Controllers\Front\BoardController;

/** @var \Convoro\Engine\Routing\Router $router */

/*
 * What the board says right now, as JSON.
 *
 * 🚨 This is the whole of "live". The score changes while somebody is reading
 * the page and nothing about a rendered widget knows that, so the board asks
 * this every so often and updates itself. Without it a live scoreboard is a
 * photograph of a live scoreboard.
 *
 * Open to guests, because the scoreboard is. The permission that matters is
 * the one on the LINK into the thread, and that is decided per reader inside
 * the controller exactly as it is for the rendered widget.
 */
$router->get('/gameday/board.json', [BoardController::class, 'show'], 'gameday.board');
