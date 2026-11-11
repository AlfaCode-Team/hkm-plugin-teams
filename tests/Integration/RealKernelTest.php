<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Integration;

require_once __DIR__ . '/../Support/trans_or_stub.php';

use AlfacodeTeam\Ground\BootedGround;
use AlfacodeTeam\Ground\Fakes\MigratedSqlite;
use AlfacodeTeam\Ground\PluginGround;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ScopeViolationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\TestCase;
use Plugins\Authorization\Provider as AuthorizationProvider;
use Plugins\Teams\API\Contracts\TeamMembershipContract;
use Plugins\Teams\API\Contracts\TeamServiceContract;
use Plugins\Teams\Application\Ports\TeamStore;
use Plugins\Teams\Provider;
use Tests\Plugins\Teams\Support\Harness;
use Tests\Plugins\Teams\Support\Stubs\DatabaseManagement\Provider as DatabaseStub;
use Tests\Plugins\Teams\Support\Stubs\I18n\Provider as I18nStub;
use Tests\Plugins\Teams\Support\Stubs\ResolverBinder\Provider as ResolverBinderStub;

/**
 * The plugin booted in the REAL kernel next to the REAL Authorization plugin:
 * module.json compiled by the boot pipeline, routes served by the real
 * HttpPipeline, controllers resolved under scope isolation, the policy loaded
 * from the bundled CSV through AUTHZ_POLICY_FILE.
 *
 * Not covered: a real MySQL/PostgreSQL, the `auth` filter (stubbed by the
 * ground; SecurityFilters is another plugin), and a real browser.
 */
final class RealKernelTest extends TestCase
{
    private ?MigratedSqlite $db = null;
    private ?BootedGround $g = null;

    protected function setUp(): void
    {
        $root     = \dirname(__DIR__, 2);
        $this->db = MigratedSqlite::migrated($root . '/database/migrations', 'teams-e2e');

        $this->g = PluginGround::for(Provider::class, AuthorizationProvider::class, DatabaseStub::class, I18nStub::class, ResolverBinderStub::class)
            // A project route behind the real `can:` filter; the project binds TeamSubjectResolver.
            ->routes([[
                'method' => 'PUT', 'path' => '/team-settings/{teamId:alphanum}',
                'handler' => 'Tests\\Plugins\\Teams\\Support\\SettingsController@update',
                'filters' => ['can:team,update'], 'requires' => ['authorization.policy', 'test.team-resolver'],
            ]])
            ->port(DatabasePort::class, $this->db)
            ->env(['AUTHZ_POLICY_FILE' => $root . '/config/policy.teams.csv', 'AUTHZ_MODEL_PATH' => \dirname($root) . '/hkm-plugin-authorization/config/rbac_model.conf'])
            ->as(Identity::asUser('owner', 'tenant-a', [], [], 'jwt', '', 'owner@example.com'))
            ->boot();
    }

    protected function tearDown(): void
    {
        $this->g?->destroy();
        $this->db?->discard();
    }

    private function g(): BootedGround
    {
        return $this->g ?? throw new \LogicException('not booted');
    }

    private function as(string $user, string $tenant = 'tenant-a', string $email = ''): void
    {
        $this->g()->as(Identity::asUser($user, $tenant, [], [], 'jwt', '', $email));
    }

    public function test_the_whole_lifecycle_over_http(): void
    {
        $created = $this->g()->json('POST', '/api/teams', ['name' => 'Platform']);
        self::assertSame(201, $created->status(), $created->describe());
        $id = $created->at('data.id');
        self::assertSame(['owner'], $created->at('data.myRoles'));

        self::assertSame(200, $this->g()->get("/api/teams/{$id}")->status());
        self::assertSame($id, $this->g()->get('/api/teams')->at('data.0.id'));

        // Invite -> accept by the addressee.
        $invite = $this->g()->json('POST', "/api/teams/{$id}/invites", ['email' => 'jane@example.com', 'roles' => ['admin']]);
        self::assertSame(201, $invite->status(), $invite->describe());
        $inviteId = $invite->at('data.invite.id');
        $token    = $invite->at('data.token');

        $this->as('jane', 'tenant-a', 'jane@example.com');
        $accepted = $this->g()->json('POST', "/api/team-invites/{$inviteId}/accept", ['token' => $token]);
        self::assertSame(200, $accepted->status(), $accepted->describe());
        self::assertSame(['admin'], $accepted->at('data.roles'));

        // Jane (admin) renames; a second accept is a 409 with a reason.
        self::assertSame('Renamed', $this->g()->json('PATCH', "/api/teams/{$id}", ['name' => 'Renamed'])->at('data.name'));
        $again = $this->g()->json('POST', "/api/team-invites/{$inviteId}/accept", ['token' => $token]);
        self::assertSame(409, $again->status());
        self::assertSame('team.invite.not_pending', $again->errorCode());

        // Jane leaves; she can no longer see it.
        self::assertSame(204, $this->g()->json('POST', "/api/teams/{$id}/leave")->status());
        self::assertSame(404, $this->g()->get("/api/teams/{$id}")->status());
    }

    public function test_refusals_carry_their_status_and_code(): void
    {
        $id = $this->g()->json('POST', '/api/teams', ['name' => 'Platform'])->at('data.id');
        $this->db?->execute(
            'INSERT INTO team_memberships (id, tenant_id, team_id, user_id, roles, joined_at, updated_at) VALUES (:i, :t, :team, :u, :r, 1, 1)',
            ['i' => bin2hex(random_bytes(16)), 't' => 'tenant-a', 'team' => $id, 'u' => 'member', 'r' => '["member"]'],
        );

        $this->as('stranger');
        $stranger = $this->g()->get("/api/teams/{$id}");
        self::assertSame(404, $stranger->status());
        self::assertSame('team.not_found', $stranger->errorCode());

        $this->as('member');
        $member = $this->g()->json('PATCH', "/api/teams/{$id}", ['name' => 'Nope']);
        self::assertSame(403, $member->status());
        self::assertSame('team.forbidden', $member->errorCode());

        // Another tenant, same user id.
        $this->as('owner', 'tenant-b');
        self::assertSame(404, $this->g()->get("/api/teams/{$id}")->status());
    }

    public function test_the_can_filter_judges_a_team_role_end_to_end(): void
    {
        // The REAL PolicyFilterStage, the REAL policy file, and the project's
        // resolver composed from TeamSubjectResolver. Nothing puts a role in the
        // session: the Identity carries none.
        $id = $this->g()->json('POST', '/api/teams', ['name' => 'Platform'])->at('data.id');
        $this->db?->execute(
            'INSERT INTO team_memberships (id, tenant_id, team_id, user_id, roles, joined_at, updated_at) VALUES (:i, :t, :team, :u, :r, 1, 1)',
            ['i' => bin2hex(random_bytes(16)), 't' => 'tenant-a', 'team' => $id, 'u' => 'plain', 'r' => '["member"]'],
        );
        $this->db?->execute(
            'INSERT INTO team_memberships (id, tenant_id, team_id, user_id, roles, joined_at, updated_at) VALUES (:i, :t, :team, :u, :r, 1, 1)',
            ['i' => bin2hex(random_bytes(16)), 't' => 'tenant-a', 'team' => $id, 'u' => 'adm', 'r' => '["admin"]'],
        );

        // owner (team:owner inherits team:admin) is let through.
        $ok = $this->g()->json('PUT', "/team-settings/{$id}");
        self::assertSame(200, $ok->status(), $ok->describe());
        $this->as('adm');
        self::assertSame(200, $this->g()->json('PUT', "/team-settings/{$id}")->status());

        // A member holds a role, but not this action.
        $this->as('plain');
        $r = $this->g()->json('PUT', "/team-settings/{$id}");
        self::assertSame(403, $r->status(), $r->describe());

        // A stranger holds no role at all.
        $this->as('stranger');
        self::assertSame(403, $this->g()->json('PUT', "/team-settings/{$id}")->status());

        // Same user id under another tenant: no seat there.
        $this->as('adm', 'tenant-b');
        self::assertSame(403, $this->g()->json('PUT', "/team-settings/{$id}")->status());

        // Removing the admin through Teams applies on the very next request.
        $this->as('owner');
        self::assertSame(204, $this->g()->json('DELETE', "/api/teams/{$id}/members/adm")->status());
        $this->as('adm');
        self::assertSame(403, $this->g()->json('PUT', "/team-settings/{$id}")->status());
    }

    public function test_a_guest_is_refused(): void
    {
        $this->g()->as(null);

        self::assertSame(401, $this->g()->json('POST', '/api/teams', ['name' => 'X'])->status());
    }

    public function test_the_published_contracts_resolve_and_the_internals_do_not(): void
    {
        self::assertInstanceOf(TeamServiceContract::class, $this->g()->service(TeamServiceContract::class));
        self::assertInstanceOf(TeamMembershipContract::class, $this->g()->service(TeamMembershipContract::class));

        $this->expectException(ScopeViolationException::class);
        $this->g()->makeFromScope(TeamStore::class, 'kernel');
    }

    public function test_the_events_reach_the_real_event_bus(): void
    {
        $this->g()->json('POST', '/api/teams', ['name' => 'Platform']);

        self::assertTrue($this->g()->events()->dispatched('team.created'));
        self::assertTrue($this->g()->events()->dispatched('team.member.added'));
    }

    public function test_a_failed_create_leaves_no_rows_and_no_events_through_the_real_pipeline(): void
    {
        $bad = $this->g()->json('POST', '/api/teams', ['name' => '']);

        self::assertSame(422, $bad->status());
        self::assertSame('team.name.invalid', $bad->errorCode());
        self::assertSame(0, (int) $this->db?->queryOne('SELECT COUNT(*) AS n FROM teams')['n']);
        self::assertSame([], $this->g()->events()->names());
    }
}
