<?php

declare(strict_types=1);

namespace Plugins\Teams\API\Contracts;

use Plugins\Teams\API\InviteView;
use Plugins\Teams\API\IssuedInvite;
use Plugins\Teams\API\MemberView;
use Plugins\Teams\API\TeamView;

/**
 * Published: teams, memberships and invites.
 *
 * Every method acts for the Identity the service was built with, and every
 * refusal is a thrown exception:
 *
 *   404 `team.not_found`  the caller is not a member (or there is no such team —
 *                         the two are indistinguishable on purpose)
 *   403 `team.forbidden`  a member whose roles the policy does not allow it
 *   409 / 410 / 422       state conflicts and rule violations, by code
 *
 * Who may do what is NOT decided here. It is the Authorization policy, asked for
 * the caller's team roles — see the README.
 */
interface TeamServiceContract
{
    /** Create a team; the caller becomes its owner. Any signed-in user may. @param array<string, mixed> $prefs */
    public function create(string $name, array $prefs = []): TeamView;

    public function find(string $teamId): TeamView;

    /** @return list<TeamView> the teams the caller belongs to */
    public function mine(): array;

    /** Rename and/or change roster privacy. Policy: `team`/`update`. */
    public function update(string $teamId, ?string $name = null, ?string $privacy = null): TeamView;

    /** Soft-deletes. Policy: `team`/`delete`. */
    public function delete(string $teamId): void;

    /** @return array<string, mixed> Policy: `team`/`read` */
    public function prefs(string $teamId): array;

    /** Replaces the whole document. Policy: `team`/`update`. @param array<string, mixed> $prefs @return array<string, mixed> */
    public function updatePrefs(string $teamId, array $prefs): array;

    /**
     * Policy: `team`/`read`, and under restricted privacy `team`/`members.read`
     * for anything beyond the caller's own membership.
     *
     * @return list<MemberView>
     */
    public function members(string $teamId): array;

    /**
     * Policy: `team`/`members.update`; changing anything involving the `owner`
     * role also needs `team`/`owners.manage`. The last owner cannot be demoted.
     *
     * @param list<string> $roles
     */
    public function changeRoles(string $teamId, string $userId, array $roles): MemberView;

    /**
     * Remove a member. Removing YOURSELF is leaving and needs no policy;
     * removing someone else needs `team`/`members.remove` (+ `owners.manage` for
     * an owner). The last owner cannot be removed.
     */
    public function removeMember(string $teamId, string $userId): void;

    /** Policy: `team`/`invite` (+ `owners.manage` to invite an owner). @param list<string> $roles */
    public function invite(string $teamId, string $email, array $roles = ['member']): IssuedInvite;

    /** @return list<InviteView> pending only. Policy: `team`/`invite` */
    public function pendingInvites(string $teamId): array;

    /** Policy: `team`/`invite`. */
    public function revokeInvite(string $teamId, string $inviteId): void;

    /**
     * Accept with the secret from the invitation. Needs no policy: the invitee
     * is not a member yet. An invite can be accepted once.
     */
    public function acceptInvite(string $inviteId, string $token): MemberView;

    public function declineInvite(string $inviteId, string $token): void;
}
