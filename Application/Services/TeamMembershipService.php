<?php

declare(strict_types=1);

namespace Plugins\Teams\Application\Services;

use Plugins\Teams\API\Contracts\TeamMembershipContract;
use Plugins\Teams\API\MembershipView;
use Plugins\Teams\Application\Ports\TeamStore;
use Plugins\Teams\Domain\TeamRole;

/** Live membership reads. See {@see TeamMembershipContract} for what it is and is not. */
final class TeamMembershipService implements TeamMembershipContract
{
    public function __construct(private readonly TeamStore $store) {}

    public function rolesIn(string $teamId, string $userId): array
    {
        if ($teamId === '' || $userId === '') {
            return [];
        }

        $membership = $this->store->findMembership($teamId, $userId);

        return $membership === null ? [] : TeamRole::values($membership->roles());
    }

    public function teamExists(string $teamId): bool
    {
        return $teamId !== '' && $this->store->findTeam($teamId) !== null;
    }

    public function policyRolesIn(string $teamId, string $userId): array
    {
        return array_map(
            static fn (string $role): string => TeamRole::POLICY_PREFIX . $role,
            $this->rolesIn($teamId, $userId),
        );
    }

    public function membershipsOf(string $userId): array
    {
        if ($userId === '') {
            return [];
        }

        return array_map(
            static fn ($m): MembershipView => new MembershipView($m->teamId, $m->userId, TeamRole::values($m->roles())),
            $this->store->membershipsOfUser($userId),
        );
    }
}
