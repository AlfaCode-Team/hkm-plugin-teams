<?php

declare(strict_types=1);

namespace Plugins\Teams\API;

/** A team as one particular caller may see it. */
final readonly class TeamView
{
    /**
     * @param list<string>         $myRoles the CALLER's roles in this team
     * @param array<string, mixed> $prefs   empty unless the caller asked for them
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $privacy,
        public int $createdAt,
        public int $updatedAt,
        public array $myRoles,
        public int $memberCount,
        public array $prefs = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
