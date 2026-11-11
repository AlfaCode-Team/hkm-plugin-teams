<?php

declare(strict_types=1);

namespace Plugins\Teams\API;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Authorization\API\Contracts\SubjectResolverContract;
use Plugins\Authorization\API\Subject;
use Plugins\Teams\API\Contracts\TeamMembershipContract;

/**
 * The bridge from a team seat to Authorization's `can:` route filter.
 *
 * Authorization's filter asks ONE project-bound {@see SubjectResolverContract}
 * who the caller is and which roles they hold here, then checks the policy for
 * those roles. A plugin cannot bind it for the project (there is exactly one),
 * so this class is given to the project to COMPOSE into its own resolver:
 *
 *     $c->bind(SubjectResolverContract::class, fn ($c) => new TeamSubjectResolver(
 *         $c->make(TeamMembershipContract::class),
 *         $existingStaffResolver,           // optional: seat roles are merged in
 *     ));
 *
 * On a route that carries the team id (`/teams/{teamId}/…`, parameter name
 * configurable) the subject's roles are the caller's roles IN THAT TEAM, read
 * live and prefixed `team:`. A non-member gets an empty role list, which the
 * filter refuses as DenialReason::NoRole. On any other route this class has
 * nothing to say and defers to the fallback (or returns null, which makes the
 * filter behave as if no resolver were bound).
 *
 * It reads no session and no Identity permission: nothing has to have put
 * `team:update` anywhere for the check to work, which is the failure mode of
 * every gate that expects a role to be copied into a token.
 */
final class TeamSubjectResolver implements SubjectResolverContract
{
    public function __construct(
        private readonly TeamMembershipContract $teams,
        private readonly ?SubjectResolverContract $fallback = null,
        private readonly string $teamParameter = 'teamId',
    ) {}

    public function resolve(Request $request): ?Subject
    {
        $inherited = $this->fallback?->resolve($request);
        $identity  = $request->identity();
        $params    = $request->attribute('route_params', []);
        $teamId    = \is_array($params) ? ($params[$this->teamParameter] ?? null) : null;

        if ($identity === null || $identity->isGuest() || !\is_string($teamId) || $teamId === '') {
            return $inherited;
        }

        $roles = $this->teams->policyRolesIn($teamId, $identity->userId);

        if ($inherited !== null) {
            return new Subject($inherited->id, [...$inherited->roles, ...$roles], $inherited->domain ?? $identity->tenantId);
        }

        return new Subject($identity->userId, $roles, $identity->tenantId);
    }
}
