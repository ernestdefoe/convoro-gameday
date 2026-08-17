<?php

declare(strict_types=1);

return [
    'name' => 'Game Day',

    'setting_enabled' => 'Open a thread for every game',
    'setting_enabled_hint' => 'Off until you turn it on. A thread opens before kickoff in the home team\'s forum, goes live when the game starts, and keeps the score afterwards.',

    'setting_lead' => 'Open the thread this many minutes before kickoff',
    'setting_lead_hint' => 'Three hours by default: long enough that people arrive to something already there, short enough that the front page is not a wall of tomorrow\'s games.',

    'setting_recaps' => 'Post the final score when the game ends',
    'setting_fallback' => 'Forum for games with no team forum',
    'setting_fallback_hint' => 'A neutral-site game belongs to neither team. Without this, those games get no thread.',

    'setting_author' => 'Post these threads as',
    'setting_author_hint' => 'A real account, chosen by you. A post from a member nobody recognises reads as a bot on a board that has never had one.',
];
