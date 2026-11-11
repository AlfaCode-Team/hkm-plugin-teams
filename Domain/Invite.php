<?php

declare(strict_types=1);

namespace Plugins\Teams\Domain;

use Plugins\Teams\Domain\Events\TeamDomainEvent;

/**
 * An offer of a seat, addressed to an email, redeemable ONCE with a secret.
 *
 * The secret is never stored: only its SHA-256 is. The token is 192 random bits,
 * so a fast hash is the right one here (there is nothing to brute-force) and
 * HashingPort, which exists for low-entropy passwords, would only add a cost.
 * A database read therefore cannot be turned into a working invite.
 *
 * `accept()` and `decline()` move the invite out of `pending`; the repository
 * then writes that with a conditional UPDATE, so two simultaneous accepts cannot
 * both win even though both pass the check here.
 */
final class Invite
{
    /** @var list<TeamDomainEvent> */
    private array $events = [];

    /** @param list<TeamRole> $roles */
    private function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $teamId,
        public readonly string $email,
        public readonly array $roles,
        public readonly string $tokenHash,
        public readonly string $invitedBy,
        private InviteStatus $status,
        public readonly int $expiresAt,
        public readonly int $createdAt,
        private ?int $resolvedAt,
        private ?string $resolvedBy,
    ) {}

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** A fresh secret. 24 random bytes, hex. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));

        if ($email === '' || \strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('team.invite.email_invalid');
        }

        return $email;
    }

    /** @param list<TeamRole> $roles */
    public static function issue(
        string $id,
        string $tenantId,
        string $teamId,
        string $email,
        array $roles,
        string $token,
        string $invitedBy,
        int $now,
        int $ttlSeconds,
    ): self {
        if ($roles === []) {
            throw new \DomainException('team.role.required');
        }
        if ($ttlSeconds < 60) {
            throw new \DomainException('team.invite.ttl_invalid');
        }

        $invite = new self(
            $id, $tenantId, $teamId, self::normalizeEmail($email), $roles, self::hashToken($token),
            $invitedBy, InviteStatus::Pending, $now + $ttlSeconds, $now, null, null,
        );
        $invite->events[] = new TeamDomainEvent('invite.created', $teamId, [
            'inviteId' => $id, 'email' => $invite->email, 'roles' => TeamRole::values($roles),
        ]);

        return $invite;
    }

    /** @param list<TeamRole> $roles */
    public static function reconstitute(
        string $id,
        string $tenantId,
        string $teamId,
        string $email,
        array $roles,
        string $tokenHash,
        string $invitedBy,
        InviteStatus $status,
        int $expiresAt,
        int $createdAt,
        ?int $resolvedAt,
        ?string $resolvedBy,
    ): self {
        return new self($id, $tenantId, $teamId, $email, $roles, $tokenHash, $invitedBy, $status, $expiresAt, $createdAt, $resolvedAt, $resolvedBy);
    }

    public function status(): InviteStatus
    {
        return $this->status;
    }

    public function resolvedAt(): ?int
    {
        return $this->resolvedAt;
    }

    public function resolvedBy(): ?string
    {
        return $this->resolvedBy;
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** Constant-time. */
    public function tokenMatches(string $token): bool
    {
        return hash_equals($this->tokenHash, self::hashToken($token));
    }

    public function accept(string $userId, int $now): void
    {
        $this->assertOpen($now);

        $this->status     = InviteStatus::Accepted;
        $this->resolvedAt = $now;
        $this->resolvedBy = $userId;
        $this->events[]   = new TeamDomainEvent('invite.accepted', $this->teamId, ['inviteId' => $this->id, 'userId' => $userId]);
    }

    public function decline(string $userId, int $now): void
    {
        $this->assertOpen($now);

        $this->status     = InviteStatus::Declined;
        $this->resolvedAt = $now;
        $this->resolvedBy = $userId;
        $this->events[]   = new TeamDomainEvent('invite.declined', $this->teamId, ['inviteId' => $this->id, 'userId' => $userId]);
    }

    public function revoke(string $userId, int $now): void
    {
        if ($this->status !== InviteStatus::Pending) {
            throw new \DomainException('team.invite.not_pending');
        }

        $this->status     = InviteStatus::Revoked;
        $this->resolvedAt = $now;
        $this->resolvedBy = $userId;
        $this->events[]   = new TeamDomainEvent('invite.revoked', $this->teamId, ['inviteId' => $this->id]);
    }

    /** @return list<TeamDomainEvent> returns AND clears the buffer */
    public function releaseEvents(): array
    {
        $events       = $this->events;
        $this->events = [];

        return $events;
    }

    private function assertOpen(int $now): void
    {
        if ($this->status !== InviteStatus::Pending) {
            throw new \DomainException('team.invite.not_pending');
        }
        if ($this->isExpired($now)) {
            throw new \DomainException('team.invite.expired');
        }
    }
}
