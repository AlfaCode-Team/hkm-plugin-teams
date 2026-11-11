<?php

declare(strict_types=1);

namespace Plugins\Teams\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Teams\API\Contracts\TeamServiceContract;

/**
 * Teams, members and prefs. Each action: read the input, call the service, answer.
 * Who may do what is decided in the service, by the Authorization policy.
 */
final class TeamController
{
    public function __construct(private readonly TeamServiceContract $teams) {}

    public function index(Request $request): Response
    {
        return Response::json(['data' => array_map(static fn ($t) => $t->toArray(), $this->teams->mine())]);
    }

    public function create(Request $request): Response
    {
        $prefs = $request->input('prefs', []);

        return Response::created(['data' => $this->teams->create((string) $request->input('name'), \is_array($prefs) ? $prefs : [])->toArray()]);
    }

    public function show(Request $request, string $teamId): Response
    {
        return Response::json(['data' => $this->teams->find($teamId)->toArray()]);
    }

    public function update(Request $request, string $teamId): Response
    {
        $name = $request->input('name');
        $privacy = $request->input('privacy');

        return Response::json(['data' => $this->teams->update($teamId, \is_string($name) ? $name : null, \is_string($privacy) ? $privacy : null)->toArray()]);
    }

    public function destroy(Request $request, string $teamId): Response
    {
        $this->teams->delete($teamId);

        return Response::noContent();
    }

    public function prefs(Request $request, string $teamId): Response
    {
        return Response::json(['data' => $this->teams->prefs($teamId)]);
    }

    public function updatePrefs(Request $request, string $teamId): Response
    {
        $prefs = $request->input('prefs');

        return Response::json(['data' => $this->teams->updatePrefs($teamId, \is_array($prefs) ? $prefs : throw new ValidationException(['prefs' => 'team.prefs.invalid']))]);
    }

    public function members(Request $request, string $teamId): Response
    {
        return Response::json(['data' => array_map(static fn ($m) => $m->toArray(), $this->teams->members($teamId))]);
    }

    public function changeRoles(Request $request, string $teamId, string $userId): Response
    {
        return Response::json(['data' => $this->teams->changeRoles($teamId, $userId, (array) $request->input('roles', []))->toArray()]);
    }

    public function removeMember(Request $request, string $teamId, string $userId): Response
    {
        $this->teams->removeMember($teamId, $userId);

        return Response::noContent();
    }

    public function leave(Request $request, string $teamId): Response
    {
        $this->teams->removeMember($teamId, (string) $request->identity()?->userId);

        return Response::noContent();
    }
}
