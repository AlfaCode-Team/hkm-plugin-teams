<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Support\Stubs\ResolverBinder;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\Authorization\API\Contracts\SubjectResolverContract;
use Plugins\Teams\API\Contracts\TeamMembershipContract;
use Plugins\Teams\API\TeamSubjectResolver;

/**
 * Plays the PROJECT: the one module that binds Authorization's
 * SubjectResolverContract, composing TeamSubjectResolver into it exactly as the
 * README tells a project to.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'test.team-resolver';
    }

    public function requires(): array
    {
        return ['team.management', 'authorization.policy'];
    }

    public function exposes(): array
    {
        return [];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bind(
            SubjectResolverContract::class,
            static fn (ModuleContainer $c): SubjectResolverContract => new TeamSubjectResolver($c->make(TeamMembershipContract::class)),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void {}
}
