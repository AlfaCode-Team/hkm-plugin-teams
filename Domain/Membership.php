<?php

declare(strict_types=1);

namespace Plugins\Teams\Domain;

use Plugins\Teams\Domain\Events\TeamDomainEvent;

/** One user's seat in one team, with the roles they hold THERE. */
final class Membership
{
    /** @var list<TeamDomainEvent> */
    private array $events = [];

    /** @param list<TeamRole> $roles */
    private function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $teamId,
        public readonly string $userId,
        private array $roles,
        public readonly int $joinedAt,
        private int $updatedAt,
    ) {}

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @param list<TeamRole> $roles */
    public static function join(string $id, string $tenantId, string $teamId, string $userId, array $roles, int $now): self
    {
        if ($userId === '') {
            throw new \DomainException('team.member.user_required');
        }
        if ($roles === []) {
            throw new \DomainException('team.role.required');
        }

        $m = new self($id, $tenantId, $teamId, $userId, $roles, $now, $now);
        $m->events[] = new TeamDomainEvent('member.added', $teamId, ['userId' => $userId, 'roles' => TeamRole::values($roles)]);

        return $m;
    }

    /** @param list<TeamRole> $roles */
    public static function reconstitute(
        string $id,
        string $tenantId,
        string $teamId,
        string $userId,
        array $roles,
        int $joinedAt,
        int $updatedAt,
    ): self {
        return new self($id, $tenantId, $teamId, $userId, $roles, $joinedAt, $updatedAt);
    }

    /** @return list<TeamRole> */
    public function roles(): array
    {
        return $this->roles;
    }

    public function updatedAt(): int
    {
        return $this->updatedAt;
    }

    public function hasRole(string $role): bool
    {
        return TeamRole::contains($this->roles, $role);
    }

    public function isOwner(): bool
    {
        return $this->hasRole(TeamRole::OWNER);
    }

    /** @param list<TeamRole> $roles */
    public function changeRoles(array $roles, int $now): void
    {
        if ($roles === []) {
            throw new \DomainException('team.role.required');
        }

        $this->roles     = $roles;
        $this->updatedAt = $now;
        $this->events[]  = new TeamDomainEvent('member.roles_changed', $this->teamId, [
            'userId' => $this->userId,
            'roles'  => TeamRole::values($roles),
        ]);
    }

    public function recordRemoval(): void
    {
        $this->events[] = new TeamDomainEvent('member.removed', $this->teamId, ['userId' => $this->userId]);
    }

    /** @return list<TeamDomainEvent> returns AND clears the buffer */
    public function releaseEvents(): array
    {
        $events       = $this->events;
        $this->events = [];

        return $events;
    }
}
