<?php

declare(strict_types=1);

namespace Plugins\Teams\Domain;

enum InviteStatus: string
{
    case Pending  = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked  = 'revoked';
}
