<?php

declare(strict_types=1);

namespace Plugins\Teams\Application\Ports;

use Plugins\Teams\Domain\Invite;
use Plugins\Teams\Domain\Membership;
use Plugins\Teams\Domain\Team;

/**
 * Persistence for teams, memberships and invites. Implemented by the repository.
 *
 * EVERY method is scoped to the current tenant and to teams that are not
 * deleted. Neither is a parameter: a caller that could omit the tenant could
 * leak across it.
 */
interface TeamStore
{
    public function insertTeam(Team $team): void;

    public function findTeam(string $teamId): ?Team;

    /** @return list<Team> */
    public function teamsOfUser(string $userId): array;

    public function saveTeam(Team $team): void;

    /**
     * Take the team's row lock for the rest of the transaction, so two
     * concurrent role changes cannot both read "two owners" and both demote one.
     * Existence is not reported: the caller has already loaded the team.
     */
    public function lockTeam(string $teamId, int $now): void;

    /** False when the user already holds a seat (nothing is written). */
    public function insertMembership(Membership $membership): bool;

    public function findMembership(string $teamId, string $userId): ?Membership;

    /** @return list<Membership> */
    public function membershipsOfTeam(string $teamId): array;

    /** @return list<Membership> only in teams that are not deleted */
    public function membershipsOfUser(string $userId): array;

    public function countMembers(string $teamId): int;

    public function saveMembership(Membership $membership): void;

    public function deleteMembership(string $teamId, string $userId): bool;

    public function deleteMembershipsOfTeam(string $teamId): int;

    public function insertInvite(Invite $invite): void;

    public function findInvite(string $inviteId): ?Invite;

    /** @return list<Invite> */
    public function pendingInvites(string $teamId, int $now): array;

    /** True when a not-yet-expired pending invite for this address already exists. */
    public function hasPendingInviteFor(string $teamId, string $email, int $now): bool;

    /**
     * Write a resolution (accepted / declined / revoked), but ONLY if the row is
     * still pending. False means somebody else resolved it first.
     */
    public function resolveInvite(Invite $invite): bool;
}
