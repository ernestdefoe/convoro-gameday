<?php

declare(strict_types=1);

namespace Convoro\Extensions\Gameday\Services\Sports;

/**
 * Which sports this install knows about.
 *
 * 🚨 A registry rather than a `match` inside `Recap`, so an application can add
 * one without editing a file it does not own — the same argument the widget and
 * page-block registries make. Adding a league is a class and a line.
 *
 * 🚨 An unknown key falls back to gridiron rather than throwing. A settings
 * value naming a sport that has been removed is somebody's install, not a
 * programming error, and a recap in the wrong vocabulary is a far better
 * outcome than a scheduled job that dies.
 */
final class Sports
{
    public const DEFAULT = 'gridiron';

    /** @var array<string, Sport> */
    private array $sports = [];

    public function __construct()
    {
        $this->register(new Gridiron());
        $this->register(new Soccer());
    }

    public function register(Sport $sport): void
    {
        $this->sports[$sport->key()] = $sport;
    }

    public function get(string $key): Sport
    {
        return $this->sports[$key] ?? $this->sports[self::DEFAULT];
    }

    public function has(string $key): bool
    {
        return isset($this->sports[$key]);
    }

    /** @return array<string, string> key => name, for a dropdown */
    public function choices(): array
    {
        $out = [];

        foreach ($this->sports as $key => $sport) {
            $out[$key] = $sport->name();
        }

        return $out;
    }
}
