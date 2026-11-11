<?php

declare(strict_types=1);

namespace Plugins\Teams\API;

/** An invite as it is listed. Never carries the secret. */
final readonly class InviteView
{
    /** @param list<string> $roles */
    public function __construct(
        public string $id,
        public string $teamId,
        public string $email,
        public array $roles,
        public string $status,
        public int $expiresAt,
        public int $createdAt,
        public string $invitedBy,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
