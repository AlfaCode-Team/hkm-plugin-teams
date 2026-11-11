<?php

declare(strict_types=1);

namespace Plugins\Teams\Infrastructure\Config;

/** The plugin's settings, read once. */
final readonly class TeamsConfig
{
    public function __construct(
        public int $inviteTtlSeconds = 604800,
        public int $maxPrefsBytes = 16384,
        public bool $requireInviteEmailMatch = true,
    ) {}

    public static function fromEnv(): self
    {
        $ttl   = (int) (env('TEAMS_INVITE_TTL_SECONDS') ?? 604800);
        $bytes = (int) (env('TEAMS_MAX_PREFS_BYTES') ?? 16384);
        $match = env('TEAMS_INVITE_REQUIRE_EMAIL_MATCH');

        return new self(
            max(60, $ttl),
            max(64, $bytes),
            $match === null || $match === '' ? true : filter_var($match, FILTER_VALIDATE_BOOLEAN),
        );
    }
}
