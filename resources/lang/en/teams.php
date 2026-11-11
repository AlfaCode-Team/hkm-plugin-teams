<?php

declare(strict_types=1);

/** The sentence for each error code the service throws. The code is the exception message. */
return [
    'errors' => [
        'team.not_found'            => 'That team does not exist.',
        'team.forbidden'            => 'You are not allowed to do that in this team.',
        'team.unauthenticated'      => 'You need to be signed in.',
        'team.name.invalid'         => 'A team name is required and must be at most 128 characters.',
        'team.owner.required'       => 'A team needs an owner.',
        'team.deleted'              => 'That team has been deleted.',
        'team.update.empty'         => 'Nothing to update.',
        'team.privacy.invalid'      => 'Privacy must be "members" or "restricted".',
        'team.prefs.invalid'        => 'Preferences must be an object that can be stored as JSON.',
        'team.prefs.too_large'      => 'Those preferences are too large.',
        'team.role.invalid'         => 'A role must be 1 to 32 characters: lowercase letters, digits, "-" or "_", starting with a letter.',
        'team.role.required'        => 'At least one role is required.',
        'team.role.too_many'        => 'A member can hold at most 8 roles.',
        'team.member.not_found'     => 'That person is not a member of this team.',
        'team.member.user_required' => 'A member needs a user.',
        'team.last_owner'           => 'A team must keep at least one owner.',
        'team.already_member'       => 'You are already a member of this team.',
        'team.invite.invalid'       => 'That invitation is not valid.',
        'team.invite.expired'       => 'That invitation has expired.',
        'team.invite.not_pending'   => 'That invitation has already been used or withdrawn.',
        'team.invite.already_pending' => 'There is already an open invitation for that address.',
        'team.invite.wrong_account' => 'That invitation was sent to a different email address.',
        'team.invite.email_invalid' => 'Enter a valid email address.',
        'team.invite.ttl_invalid'   => 'An invitation must be valid for at least a minute.',
    ],
];
