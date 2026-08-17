<?php

declare(strict_types=1);

use Convoro\Engine\Http\Middleware\RequireAdmin;
use Convoro\Extensions\Gameday\Controllers\Admin\GamedayController;

/** @var \Convoro\Engine\Routing\Router $router */

$router->group()
    ->prefix('/admin/gameday')
    ->middleware(RequireAdmin::class)
    ->group(function ($router) {
        $router->get('/', [GamedayController::class, 'index'], 'admin.gameday');
        $router->post('/', [GamedayController::class, 'save'], 'admin.gameday.save');
    });
