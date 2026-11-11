<?php

declare(strict_types=1);

namespace Plugins\Teams\API;

final readonly class MemberView
{
    /** @param list<string> $roles */
    public function __construct(
        public string $userId,
        public array $roles,
        public int $joinedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
