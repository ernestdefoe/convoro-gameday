<?php

declare(strict_types=1);

return [
    'name' => 'Game Day',
    'nav' => 'Game Day',
    'intro' => 'A thread for every game: opened before kickoff in the home team\'s forum, live while it is played, and kept afterwards with the score.',

    'needs_picks' => 'Game Day reads the fixtures Picks syncs, and Picks is not installed here. Nothing below will do anything until it is.',
    'next_games' => 'The next few games',
    'no_games' => 'No fixtures ahead. Picks syncs the schedule; once it has, they appear here.',
    'game' => 'Game',
    'has_the_ball' => 'has the ball',
    'kickoff' => 'Kickoff',
    'thread' => 'Thread',
    'thread_none' => 'Not yet',
    'state_open' => 'Open',
    'state_live' => 'Live now',
    'state_resolved' => 'Finished',

    'settings' => 'Settings',
    'no_fallback' => 'No fallback — skip those games',
    'save' => 'Save',
    'record_title' => 'Their pick record this season',

    'widget_label' => 'Scoreboard',
    'playing_now' => 'Playing now',
    'to_the_thread' => 'To the thread',
    'saved' => 'Saved.',

    'setting_enabled' => 'Open a thread for every game',
    'setting_enabled_hint' => 'Off until you turn it on. A thread opens before kickoff in the home team\'s forum, goes live when the game starts, and keeps the score afterwards.',

    'setting_lead' => 'Open the thread this many minutes before kickoff',
    'setting_lead_hint' => 'Three hours by default: long enough that people arrive to something already there, short enough that the front page is not a wall of tomorrow\'s games.',

    'setting_recaps' => 'Post the final score when the game ends',
    'setting_fallback' => 'Forum for games with no team forum',
    'setting_fallback_hint' => 'A neutral-site game belongs to neither team. Without this, those games get no thread.',

    'setting_panel' => 'Show this page beside a live game thread',
    'setting_panel_hint' => 'A page built in Pages, shown in the panel next to the conversation while the game is on. Put the Game Day scoreboard block on it and the score sits beside the thread rather than on another tab.',
    'no_panel' => 'No panel — just the thread',

    'setting_author' => 'Post these threads as',
    'setting_author_hint' => 'The username of a real account, chosen by you. A post from a member nobody recognises reads as a bot on a board that has never had one.',
    'author_unknown' => 'Everything else was saved, but there is nobody here called “{name}” — so the account these threads post as is unchanged.',
];
