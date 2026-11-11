<?php

declare(strict_types=1);

namespace Plugins\Teams\Domain;

/**
 * Who may see the roster of a team.
 *
 *   Members     any member sees every member (the default: a team is a group).
 *   Restricted  a member sees only their OWN membership; the full roster needs
 *               the `members.read` policy action, which the bundled policy
 *               gives to admins and owners.
 *
 * This is a visibility rule about OTHER people. It never hides a membership
 * from the person it belongs to.
 */
enum Privacy: string
{
    case Members    = 'members';
    case Restricted = 'restricted';
}
