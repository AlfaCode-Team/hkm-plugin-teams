<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

/** A project route behind `can:team,update`. Reached only if the filter let the request through. */
final class SettingsController
{
    public function update(Request $request, string $teamId): Response
    {
        return Response::json(['ok' => true, 'team' => $teamId]);
    }
}
