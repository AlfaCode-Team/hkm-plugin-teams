<?php

declare(strict_types=1);

namespace Plugins\Teams;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Authorization\API\Contracts\SubjectAuthorizationContract;
use Plugins\Teams\API\Contracts\TeamMembershipContract;
use Plugins\Teams\API\Contracts\TeamServiceContract;
use Plugins\Teams\Application\Ports\TeamStore;
use Plugins\Teams\Application\Services\TeamMembershipService;
use Plugins\Teams\Application\Services\TeamService;
use Plugins\Teams\Infrastructure\Config\TeamsConfig;
use Plugins\Teams\Infrastructure\Persistence\TeamConnection;
use Plugins\Teams\Infrastructure\Persistence\TeamRepository;

/**
 * Teams — wires the 'team.management' domain.
 *
 * Requires `authorization.policy` because that is where "may this role do this"
 * is answered; this plugin owns who is in which team and nothing about what
 * that allows.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'team.management';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return ['database.management', 'authorization.policy'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [TeamServiceContract::class, TeamMembershipContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(TeamsConfig::class, static fn (): TeamsConfig => TeamsConfig::fromEnv());

        // Internal bindings are shared within a request: ONE connection.
        $container->bindInternal(
            TeamConnection::class,
            static fn (ModuleContainer $c): TeamConnection => new TeamConnection($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            TeamStore::class,
            static fn (ModuleContainer $c): TeamStore => new TeamRepository(
                $c->make(TeamConnection::class)->db,
                $c->make(Identity::class),
            ),
        );

        $container->bind(
            TeamMembershipContract::class,
            static fn (ModuleContainer $c): TeamMembershipContract => new TeamMembershipService($c->make(TeamStore::class)),
        );

        $container->bind(
            TeamServiceContract::class,
            static fn (ModuleContainer $c): TeamServiceContract => new TeamService(
                store:       $c->make(TeamStore::class),
                // Over the SAME connection as the repository, not the kernel's
                // TransactionManager (which wraps a different one).
                transaction: new TransactionManager($c->make(TeamConnection::class)->db),
                collector:   $c->has(DomainEventCollector::class) ? $c->make(DomainEventCollector::class) : new DomainEventCollector(),
                eventBus:    $c->make(EventBus::class),
                identity:    $c->make(Identity::class),
                clock:       $c->has(ClockPort::class) ? $c->make(ClockPort::class) : new SystemClock(),
                authz:       $c->make(SubjectAuthorizationContract::class),
                config:      $c->make(TeamsConfig::class),
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // No filters, jobs or subscriptions. The `can:` bridge for a project's own
        // routes is TeamSubjectResolver, which the PROJECT binds (there is exactly
        // one SubjectResolverContract); see the README.
    }
}
