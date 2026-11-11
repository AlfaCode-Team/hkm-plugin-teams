<?php

declare(strict_types=1);

namespace Plugins\Teams\Domain;

use Plugins\Teams\Domain\Events\TeamDomainEvent;

/** A named group of people inside one tenant. */
final class Team
{
    public const int NAME_MAX = 128;

    /** @var list<TeamDomainEvent> */
    private array $events = [];

    /** @param array<string, mixed> $prefs */
    private function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        private string $name,
        private Privacy $privacy,
        private array $prefs,
        public readonly string $createdBy,
        public readonly int $createdAt,
        private int $updatedAt,
        private ?int $deletedAt,
    ) {}

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function create(string $id, string $tenantId, string $name, string $createdBy, int $now): self
    {
        if ($tenantId === '' || $createdBy === '') {
            throw new \DomainException('team.owner.required');
        }

        $team = new self($id, $tenantId, self::cleanName($name), Privacy::Members, [], $createdBy, $now, $now, null);
        $team->events[] = new TeamDomainEvent('created', $id, ['createdBy' => $createdBy]);

        return $team;
    }

    /** @param array<string, mixed> $prefs */
    public static function reconstitute(
        string $id,
        string $tenantId,
        string $name,
        Privacy $privacy,
        array $prefs,
        string $createdBy,
        int $createdAt,
        int $updatedAt,
        ?int $deletedAt,
    ): self {
        return new self($id, $tenantId, $name, $privacy, $prefs, $createdBy, $createdAt, $updatedAt, $deletedAt);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function privacy(): Privacy
    {
        return $this->privacy;
    }

    /** @return array<string, mixed> */
    public function prefs(): array
    {
        return $this->prefs;
    }

    public function updatedAt(): int
    {
        return $this->updatedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function deletedAt(): ?int
    {
        return $this->deletedAt;
    }

    public function rename(string $name, int $now): void
    {
        $this->name      = self::cleanName($name);
        $this->updatedAt = $now;
    }

    public function restrictRoster(Privacy $privacy, int $now): void
    {
        $this->privacy   = $privacy;
        $this->updatedAt = $now;
    }

    /**
     * Replace the preferences wholesale.
     *
     * Replace, not merge: a merge cannot express "remove this key", and the
     * caller always has the current document in hand to edit.
     *
     * @param array<string, mixed> $prefs
     */
    public function replacePrefs(array $prefs, int $maxBytes, int $now): void
    {
        $encoded = json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new \DomainException('team.prefs.invalid');
        }
        if (\strlen($encoded) > $maxBytes) {
            throw new \DomainException('team.prefs.too_large');
        }

        $this->prefs     = $prefs;
        $this->updatedAt = $now;
    }

    public function delete(int $now): void
    {
        if ($this->deletedAt !== null) {
            throw new \DomainException('team.deleted');
        }

        $this->deletedAt = $now;
        $this->updatedAt = $now;
        $this->events[]  = new TeamDomainEvent('deleted', $this->id);
    }

    /** @return list<TeamDomainEvent> returns AND clears the buffer */
    public function releaseEvents(): array
    {
        $events       = $this->events;
        $this->events = [];

        return $events;
    }

    private static function cleanName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new \DomainException('team.name.invalid');
        }

        return $name;
    }
}
