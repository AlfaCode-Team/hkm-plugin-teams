<?php

declare(strict_types=1);

namespace Plugins\Teams\API;

/** One live membership, as other plugins read it. */
final readonly class MembershipView
{
    /** @param list<string> $roles */
    public function __construct(
        public string $teamId,
        public string $userId,
        public array $roles,
    ) {}

    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
    }
}
