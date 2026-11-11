<?php

declare(strict_types=1);

/** La phrase associee a chaque code d'erreur leve par le service. Le code est le message de l'exception. */
return [
    'errors' => [
        'team.not_found'            => "Cette equipe n'existe pas.",
        'team.forbidden'            => "Vous n'avez pas le droit de faire cela dans cette equipe.",
        'team.unauthenticated'      => 'Vous devez etre connecte.',
        'team.name.invalid'         => "Un nom d'equipe est requis (128 caracteres au maximum).",
        'team.owner.required'       => "Une equipe a besoin d'un proprietaire.",
        'team.deleted'              => 'Cette equipe a ete supprimee.',
        'team.update.empty'         => 'Rien a mettre a jour.',
        'team.privacy.invalid'      => 'La confidentialite doit etre "members" ou "restricted".',
        'team.prefs.invalid'        => 'Les preferences doivent etre un objet enregistrable en JSON.',
        'team.prefs.too_large'      => 'Ces preferences sont trop volumineuses.',
        'team.role.invalid'         => 'Un role compte de 1 a 32 caracteres : minuscules, chiffres, "-" ou "_", en commencant par une lettre.',
        'team.role.required'        => 'Au moins un role est requis.',
        'team.role.too_many'        => 'Un membre peut avoir 8 roles au maximum.',
        'team.member.not_found'     => "Cette personne n'est pas membre de l'equipe.",
        'team.member.user_required' => "Un membre a besoin d'un utilisateur.",
        'team.last_owner'           => 'Une equipe doit garder au moins un proprietaire.',
        'team.already_member'       => 'Vous etes deja membre de cette equipe.',
        'team.invite.invalid'       => "Cette invitation n'est pas valide.",
        'team.invite.expired'       => 'Cette invitation a expire.',
        'team.invite.not_pending'   => 'Cette invitation a deja ete utilisee ou retiree.',
        'team.invite.already_pending' => 'Une invitation est deja en cours pour cette adresse.',
        'team.invite.wrong_account' => 'Cette invitation a ete envoyee a une autre adresse email.',
        'team.invite.email_invalid' => 'Saisissez une adresse email valide.',
        'team.invite.ttl_invalid'   => 'Une invitation doit rester valide au moins une minute.',
    ],
];
