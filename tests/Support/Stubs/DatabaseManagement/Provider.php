<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Support\Stubs\DatabaseManagement;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;

/** Stands in for the 'database.management' domain, which Authorization requires. The DatabasePort comes from the kernel's port bindings. */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'database.management';
    }

    public function requires(): array
    {
        return [];
    }

    public function exposes(): array
    {
        return [];
    }

    public function register(ModuleContainer $container): void {}

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void {}
}
