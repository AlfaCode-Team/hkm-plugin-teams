<?php

declare(strict_types=1);

namespace Plugins\Teams\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Teams\API\Contracts\TeamServiceContract;

/** Invites: issued by a team, accepted or declined by the person it names. */
final class InviteController
{
    public function __construct(private readonly TeamServiceContract $teams) {}

    public function index(Request $request, string $teamId): Response
    {
        return Response::json(['data' => array_map(static fn ($i) => $i->toArray(), $this->teams->pendingInvites($teamId))]);
    }

    public function create(Request $request, string $teamId): Response
    {
        return Response::created(['data' => $this->teams->invite($teamId, (string) $request->input('email'), (array) $request->input('roles', ['member']))->toArray()]);
    }

    public function revoke(Request $request, string $teamId, string $inviteId): Response
    {
        $this->teams->revokeInvite($teamId, $inviteId);

        return Response::noContent();
    }

    public function accept(Request $request, string $inviteId): Response
    {
        return Response::json(['data' => $this->teams->acceptInvite($inviteId, (string) $request->input('token'))->toArray()]);
    }

    public function decline(Request $request, string $inviteId): Response
    {
        $this->teams->declineInvite($inviteId, (string) $request->input('token'));

        return Response::noContent();
    }
}
