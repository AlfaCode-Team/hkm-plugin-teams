<?php

declare(strict_types=1);

namespace Plugins\Teams\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * The ONE database connection of a request.
 *
 * The Database plugin binds `DatabasePort` as a transient: every `make()` is a
 * new connection. A transaction begun on one and a repository writing through
 * another would make the rollback decorative, so the repository and the
 * TransactionManager both take the connection from this holder, which is bound
 * once per request.
 */
final readonly class TeamConnection
{
    public function __construct(public DatabasePort $db) {}
}
