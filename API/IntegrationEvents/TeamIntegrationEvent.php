<?php

declare(strict_types=1);

namespace Plugins\Teams\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * What other modules may learn about a team. Primitives only, versioned, and
 * never a secret: an invite's token is not in any payload.
 *
 * Names: team.created, team.deleted, team.member.added, team.member.removed,
 * team.member.roles_changed, team.invite.created, team.invite.accepted,
 * team.invite.declined, team.invite.revoked.
 */
final readonly class TeamIntegrationEvent implements IntegrationEventContract
{
    /** @param array<string, string|int|list<string>> $data */
    public function __construct(
        private string $eventName,
        public string $teamId,
        public string $tenantId,
        public string $occurredAt,
        public array $data = [],
    ) {}

    public function name(): string
    {
        return 'team.' . $this->eventName;
    }

    public function version(): string
    {
        return '1.0';
    }

    public function payload(): array
    {
        return ['teamId' => $this->teamId, 'tenantId' => $this->tenantId, 'occurredAt' => $this->occurredAt] + $this->data;
    }
}
