<?php

declare(strict_types=1);

namespace Plugins\Teams\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;

/**
 * Something that happened to a team, recorded by the entity that did it.
 *
 * One class with a `kind`, rather than nine near-identical ones: nothing inside
 * this module branches on the TYPE of a domain event, they exist to be
 * collected during the transaction and discarded if it rolls back.
 */
final readonly class TeamDomainEvent implements DomainEventContract
{
    /** @param array<string, string|int|list<string>> $data primitives only */
    public function __construct(
        public string $kind,
        public string $teamId,
        public array $data = [],
    ) {}
}
