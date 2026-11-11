<?php

declare(strict_types=1);

namespace Plugins\Teams\API\Contracts;

use Plugins\Teams\API\MembershipView;

/**
 * Published: who is in which team, read LIVE.
 *
 * This is what other plugins build an authorization decision from — Authorization
 * (through {@see \Plugins\Teams\API\TeamSubjectResolver}) and the ACL plugin both
 * call it. It is an INPUT to a decision and makes none itself, so it takes no
 * Identity-based permission check of its own: any code that can resolve it may
 * ask about any user. Keep it out of controllers and out of responses.
 *
 * Every answer is read from the membership table on the call. Nothing is cached
 * and nothing is taken from the session, so removing a member or deleting a team
 * takes effect on the very next question.
 *
 * The tenant is the resolved Identity's tenant. That value is a HINT, so it is
 * never trusted on its own: the lookup is keyed by (tenant, team, user) and a
 * forged tenant simply finds no row.
 */
interface TeamMembershipContract
{
    /**
     * The roles $userId holds in $teamId, or [] when they are not a member, the
     * team does not exist, was deleted, or belongs to another tenant.
     *
     * @return list<string>
     */
    public function rolesIn(string $teamId, string $userId): array;

    /**
     * Does this team exist, undeleted, in the current tenant? Lets a plugin
     * refuse a reference to a team id that is a typo or belongs to somebody else
     * before it stores it.
     */
    public function teamExists(string $teamId): bool;

    /**
     * The same roles as the Authorization policy names them (`team:owner`, …).
     *
     * @return list<string>
     */
    public function policyRolesIn(string $teamId, string $userId): array;

    /**
     * Every live team $userId belongs to in the current tenant.
     *
     * @return list<MembershipView>
     */
    public function membershipsOf(string $userId): array;
}
