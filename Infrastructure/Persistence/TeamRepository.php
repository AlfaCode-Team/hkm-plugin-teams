<?php

declare(strict_types=1);

namespace Plugins\Teams\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Teams\Application\Ports\TeamStore;
use Plugins\Teams\Domain\Invite;
use Plugins\Teams\Domain\InviteStatus;
use Plugins\Teams\Domain\Membership;
use Plugins\Teams\Domain\Privacy;
use Plugins\Teams\Domain\Team;
use Plugins\Teams\Domain\TeamRole;

/**
 * Teams, memberships and invites over DatabasePort.
 *
 * Every statement carries `tenant_id = :tenant`, taken from the Identity the
 * repository was built with. That value is a HINT, so it is not what makes the
 * answer correct on its own: memberships are keyed (tenant, team, user), and a
 * forged tenant finds no seat to inherit.
 *
 * Teams are soft-deleted (`deleted_at`) and every team read filters on it.
 * Memberships and invites have no such column: a membership is hard-deleted so
 * that a removed member stops being a member in the SAME statement, with no
 * second flag a forgotten WHERE could ignore.
 */
final class TeamRepository implements TeamStore
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity $identity,
    ) {}

    public function insertTeam(Team $team): void
    {
        $this->guard('insert team', fn () => $this->db->execute(
            'INSERT INTO teams (id, tenant_id, name, privacy, prefs, created_by, created_at, updated_at, deleted_at)
             VALUES (:id, :tenant, :name, :privacy, :prefs, :created_by, :created_at, :updated_at, NULL)',
            [
                'id' => $team->id, 'tenant' => $this->tenant($team->tenantId), 'name' => $team->name(),
                'privacy' => $team->privacy()->value, 'prefs' => $this->encode($team->prefs()),
                'created_by' => $team->createdBy, 'created_at' => $team->createdAt, 'updated_at' => $team->updatedAt(),
            ],
        ));
    }

    public function findTeam(string $teamId): ?Team
    {
        $row = $this->guard('find team', fn () => $this->db->queryOne(
            'SELECT * FROM teams WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $teamId, 'tenant' => $this->identity->tenantId],
        ));

        return $row === null ? null : $this->team($row);
    }

    public function teamsOfUser(string $userId): array
    {
        $rows = $this->guard('list teams', fn () => $this->db->query(
            'SELECT t.* FROM teams t
               JOIN team_memberships m ON m.team_id = t.id AND m.tenant_id = t.tenant_id
              WHERE m.user_id = :user AND m.tenant_id = :tenant AND t.deleted_at IS NULL
              ORDER BY t.created_at ASC, t.id ASC',
            ['user' => $userId, 'tenant' => $this->identity->tenantId],
        ));

        return array_map(fn (array $r): Team => $this->team($r), $rows);
    }

    public function saveTeam(Team $team): void
    {
        $this->guard('save team', fn () => $this->db->execute(
            'UPDATE teams SET name = :name, privacy = :privacy, prefs = :prefs, updated_at = :updated_at, deleted_at = :deleted_at
              WHERE id = :id AND tenant_id = :tenant',
            [
                'name' => $team->name(), 'privacy' => $team->privacy()->value, 'prefs' => $this->encode($team->prefs()),
                'updated_at' => $team->updatedAt(), 'deleted_at' => $team->deletedAt(),
                'id' => $team->id, 'tenant' => $this->tenant($team->tenantId),
            ],
        ));
    }

    public function lockTeam(string $teamId, int $now): void
    {
        $this->guard('lock team', fn () => $this->db->execute(
            'UPDATE teams SET updated_at = :now WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['now' => $now, 'id' => $teamId, 'tenant' => $this->identity->tenantId],
        ));
    }

    public function insertMembership(Membership $membership): bool
    {
        // Insert-if-absent: [] means "update nothing", so a second seat for the
        // same (tenant, team, user) is a no-op the unique index enforces.
        $written = $this->guard('insert membership', fn () => $this->db->upsert(
            'team_memberships',
            [
                'id' => $membership->id, 'tenant_id' => $this->tenant($membership->tenantId), 'team_id' => $membership->teamId,
                'user_id' => $membership->userId, 'roles' => $this->encode(TeamRole::values($membership->roles())),
                'joined_at' => $membership->joinedAt, 'updated_at' => $membership->updatedAt(),
            ],
            ['tenant_id', 'team_id', 'user_id'],
            [],
        ));

        return $written > 0;
    }

    public function findMembership(string $teamId, string $userId): ?Membership
    {
        $row = $this->guard('find membership', fn () => $this->db->queryOne(
            'SELECT m.* FROM team_memberships m
               JOIN teams t ON t.id = m.team_id AND t.tenant_id = m.tenant_id
              WHERE m.team_id = :team AND m.user_id = :user AND m.tenant_id = :tenant AND t.deleted_at IS NULL',
            ['team' => $teamId, 'user' => $userId, 'tenant' => $this->identity->tenantId],
        ));

        return $row === null ? null : $this->membership($row);
    }

    public function membershipsOfTeam(string $teamId): array
    {
        $rows = $this->guard('list members', fn () => $this->db->query(
            'SELECT m.* FROM team_memberships m
               JOIN teams t ON t.id = m.team_id AND t.tenant_id = m.tenant_id
              WHERE m.team_id = :team AND m.tenant_id = :tenant AND t.deleted_at IS NULL
              ORDER BY m.joined_at ASC, m.id ASC',
            ['team' => $teamId, 'tenant' => $this->identity->tenantId],
        ));

        return array_map(fn (array $r): Membership => $this->membership($r), $rows);
    }

    public function membershipsOfUser(string $userId): array
    {
        $rows = $this->guard('list memberships', fn () => $this->db->query(
            'SELECT m.* FROM team_memberships m
               JOIN teams t ON t.id = m.team_id AND t.tenant_id = m.tenant_id
              WHERE m.user_id = :user AND m.tenant_id = :tenant AND t.deleted_at IS NULL
              ORDER BY m.joined_at ASC, m.id ASC',
            ['user' => $userId, 'tenant' => $this->identity->tenantId],
        ));

        return array_map(fn (array $r): Membership => $this->membership($r), $rows);
    }

    public function countMembers(string $teamId): int
    {
        $row = $this->guard('count members', fn () => $this->db->queryOne(
            'SELECT COUNT(*) AS n FROM team_memberships WHERE team_id = :team AND tenant_id = :tenant',
            ['team' => $teamId, 'tenant' => $this->identity->tenantId],
        ));

        return (int) ($row['n'] ?? 0);
    }

    public function saveMembership(Membership $membership): void
    {
        $this->guard('save membership', fn () => $this->db->execute(
            'UPDATE team_memberships SET roles = :roles, updated_at = :updated_at
              WHERE team_id = :team AND user_id = :user AND tenant_id = :tenant',
            [
                'roles' => $this->encode(TeamRole::values($membership->roles())), 'updated_at' => $membership->updatedAt(),
                'team' => $membership->teamId, 'user' => $membership->userId, 'tenant' => $this->tenant($membership->tenantId),
            ],
        ));
    }

    public function deleteMembership(string $teamId, string $userId): bool
    {
        return $this->guard('delete membership', fn () => $this->db->execute(
            'DELETE FROM team_memberships WHERE team_id = :team AND user_id = :user AND tenant_id = :tenant',
            ['team' => $teamId, 'user' => $userId, 'tenant' => $this->identity->tenantId],
        )) > 0;
    }

    public function deleteMembershipsOfTeam(string $teamId): int
    {
        return $this->guard('delete memberships', fn () => $this->db->execute(
            'DELETE FROM team_memberships WHERE team_id = :team AND tenant_id = :tenant',
            ['team' => $teamId, 'tenant' => $this->identity->tenantId],
        ));
    }

    public function insertInvite(Invite $invite): void
    {
        $this->guard('insert invite', fn () => $this->db->execute(
            'INSERT INTO team_invites (id, tenant_id, team_id, email, roles, token_hash, invited_by, status, expires_at, created_at, resolved_at, resolved_by)
             VALUES (:id, :tenant, :team, :email, :roles, :hash, :by, :status, :expires, :created, NULL, NULL)',
            [
                'id' => $invite->id, 'tenant' => $this->tenant($invite->tenantId), 'team' => $invite->teamId, 'email' => $invite->email,
                'roles' => $this->encode(TeamRole::values($invite->roles)), 'hash' => $invite->tokenHash, 'by' => $invite->invitedBy,
                'status' => $invite->status()->value, 'expires' => $invite->expiresAt, 'created' => $invite->createdAt,
            ],
        ));
    }

    public function findInvite(string $inviteId): ?Invite
    {
        $row = $this->guard('find invite', fn () => $this->db->queryOne(
            'SELECT * FROM team_invites WHERE id = :id AND tenant_id = :tenant',
            ['id' => $inviteId, 'tenant' => $this->identity->tenantId],
        ));

        return $row === null ? null : $this->invite($row);
    }

    public function pendingInvites(string $teamId, int $now): array
    {
        $rows = $this->guard('list invites', fn () => $this->db->query(
            "SELECT * FROM team_invites
              WHERE team_id = :team AND tenant_id = :tenant AND status = 'pending' AND expires_at > :now
              ORDER BY created_at ASC, id ASC",
            ['team' => $teamId, 'tenant' => $this->identity->tenantId, 'now' => $now],
        ));

        return array_map(fn (array $r): Invite => $this->invite($r), $rows);
    }

    public function hasPendingInviteFor(string $teamId, string $email, int $now): bool
    {
        $row = $this->guard('find pending invite', fn () => $this->db->queryOne(
            "SELECT id FROM team_invites
              WHERE team_id = :team AND tenant_id = :tenant AND email = :email AND status = 'pending' AND expires_at > :now",
            ['team' => $teamId, 'tenant' => $this->identity->tenantId, 'email' => $email, 'now' => $now],
        ));

        return $row !== null;
    }

    public function resolveInvite(Invite $invite): bool
    {
        // `status = 'pending'` in the WHERE is the whole point: of two concurrent
        // accepts that both passed the in-memory check, exactly one matches.
        return $this->guard('resolve invite', fn () => $this->db->execute(
            "UPDATE team_invites SET status = :status, resolved_at = :at, resolved_by = :by
              WHERE id = :id AND tenant_id = :tenant AND status = 'pending'",
            [
                'status' => $invite->status()->value, 'at' => $invite->resolvedAt(), 'by' => $invite->resolvedBy(),
                'id' => $invite->id, 'tenant' => $this->tenant($invite->tenantId),
            ],
        )) === 1;
    }

    /**
     * An entity may only be written into the tenant this repository serves.
     * A mismatch is a programming error, and writing it anyway would be exactly
     * the cross-tenant write the column exists to prevent.
     */
    private function tenant(string $entityTenant): string
    {
        if ($entityTenant !== $this->identity->tenantId) {
            throw new RepositoryException('Entity tenant does not match the repository tenant.', layer: 'repository.teams');
        }

        return $entityTenant;
    }

    /** @param array<string, mixed>|list<string> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|list<mixed> */
    private function decode(mixed $json): array
    {
        $value = json_decode((string) $json, true);

        return \is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $r */
    private function team(array $r): Team
    {
        return Team::reconstitute(
            (string) $r['id'], (string) $r['tenant_id'], (string) $r['name'], Privacy::from((string) $r['privacy']),
            $this->decode($r['prefs']), (string) $r['created_by'], (int) $r['created_at'], (int) $r['updated_at'],
            $r['deleted_at'] === null ? null : (int) $r['deleted_at'],
        );
    }

    /** @param array<string, mixed> $r */
    private function membership(array $r): Membership
    {
        return Membership::reconstitute(
            (string) $r['id'], (string) $r['tenant_id'], (string) $r['team_id'], (string) $r['user_id'],
            TeamRole::listOf($this->decode($r['roles'])), (int) $r['joined_at'], (int) $r['updated_at'],
        );
    }

    /** @param array<string, mixed> $r */
    private function invite(array $r): Invite
    {
        return Invite::reconstitute(
            (string) $r['id'], (string) $r['tenant_id'], (string) $r['team_id'], (string) $r['email'],
            TeamRole::listOf($this->decode($r['roles'])), (string) $r['token_hash'], (string) $r['invited_by'],
            InviteStatus::from((string) $r['status']), (int) $r['expires_at'], (int) $r['created_at'],
            $r['resolved_at'] === null ? null : (int) $r['resolved_at'], $r['resolved_by'] === null ? null : (string) $r['resolved_by'],
        );
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function guard(string $what, \Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            throw new RepositoryException("Failed to {$what}.", layer: 'repository.teams', previous: $e);
        }
    }
}
