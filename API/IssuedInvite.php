<?php

declare(strict_types=1);

namespace Plugins\Teams\API;

/**
 * The ONE place an invite's secret exists in clear.
 *
 * It is returned to the caller of `invite()` so the application can put it in
 * the email it sends, and it is not recoverable afterwards: only a hash is
 * stored. It is deliberately NOT in the `team.invite.created` event, because a
 * webhook or a log subscriber would then hold a working credential.
 */
final readonly class IssuedInvite
{
    public function __construct(
        public InviteView $invite,
        public string $token,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['invite' => $this->invite->toArray(), 'token' => $this->token];
    }
}
