<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Teams, the seats in them, and the invites to take a seat.
 *
 * Times are unix seconds (bigint), written from ClockPort. Every table carries
 * `tenant_id` and every unique key includes it, so two tenants can reuse a team
 * id without ever sharing a row.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('teams', static function ($t) {
            $t->char('id', 32);
            $t->string('tenant_id', 64);
            $t->string('name', 128);
            $t->string('privacy', 12)->default('members');
            $t->text('prefs');
            $t->string('created_by', 64);
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('updated_at');
            $t->unsignedBigInteger('deleted_at')->nullable();

            $t->primary(['id'], 'pk_teams');
            $t->index(['tenant_id', 'deleted_at'], 'idx_teams_tenant');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });

        $schema->create('team_memberships', static function ($t) {
            $t->char('id', 32);
            $t->string('tenant_id', 64);
            $t->char('team_id', 32);
            $t->string('user_id', 64);
            $t->text('roles');
            $t->unsignedBigInteger('joined_at');
            $t->unsignedBigInteger('updated_at');

            $t->primary(['id'], 'pk_team_memberships');
            // One seat per person per team, enforced by the database: this is what
            // makes accepting an invite twice unable to create a second membership.
            $t->unique(['tenant_id', 'team_id', 'user_id'], 'uniq_team_member');
            $t->index(['tenant_id', 'user_id'], 'idx_team_member_user');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });

        $schema->create('team_invites', static function ($t) {
            $t->char('id', 32);
            $t->string('tenant_id', 64);
            $t->char('team_id', 32);
            $t->string('email', 254);
            $t->text('roles');
            $t->char('token_hash', 64);
            $t->string('invited_by', 64);
            $t->string('status', 10)->default('pending');
            $t->unsignedBigInteger('expires_at');
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('resolved_at')->nullable();
            $t->string('resolved_by', 64)->nullable();

            $t->primary(['id'], 'pk_team_invites');
            $t->index(['tenant_id', 'team_id', 'status'], 'idx_team_invites_team');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('team_invites');
        $schema->dropIfExists('team_memberships');
        $schema->dropIfExists('teams');
    }
};
