<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Integration;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use PHPUnit\Framework\TestCase;
use Plugins\Authorization\API\Contracts\SubjectResolverContract;
use Plugins\Authorization\API\Subject;
use Plugins\Teams\API\TeamSubjectResolver;
use Tests\Plugins\Teams\Support\Harness;

/**
 * How a team role reaches an Authorization decision from a `can:` route filter:
 * TeamSubjectResolver builds the Subject (roles read live, prefixed `team:`),
 * and the REAL SubjectAuthorizationContract judges it against the REAL policy.
 *
 * The filter stage itself (PolicyFilterStage) is Authorization's own code and
 * is covered by that plugin's suite; here it is the seam between the two that
 * is exercised.
 */
final class BridgeTest extends TestCase
{
    private ?Harness $h = null;

    protected function setUp(): void
    {
        $this->h = new Harness();
    }

    protected function tearDown(): void
    {
        $this->h?->discard();
    }

    private function h(): Harness
    {
        return $this->h ?? throw new \LogicException('not set up');
    }

    private function request(string $userId, ?string $teamId, string $tenant = 'tenant-a'): Request
    {
        $request = Request::build('PUT', '/api/teams/x')->withIdentity(Harness::identity($userId, $tenant));

        return $teamId === null ? $request : $request->withAttribute('route_params', ['teamId' => $teamId]);
    }

    private function resolver(?SubjectResolverContract $fallback = null, string $tenant = 'tenant-a'): TeamSubjectResolver
    {
        return new TeamSubjectResolver($this->h()->membership($tenant), $fallback);
    }

    private function team(): string
    {
        $id = $this->h()->as('owner')->create('Platform')->id;
        $this->h()->seat($id, 'admin', ['admin']);
        $this->h()->seat($id, 'member', ['member']);

        return $id;
    }

    public function test_a_members_team_roles_become_policy_roles_and_the_policy_decides(): void
    {
        $id = $this->team();

        $admin = $this->resolver()->resolve($this->request('admin', $id));
        self::assertNotNull($admin);
        self::assertSame(['team:admin'], $admin->roles);
        self::assertSame('tenant-a', $admin->domain);
        self::assertTrue($this->h()->authz->allows($admin, 'team', 'update'));
        self::assertFalse($this->h()->authz->allows($admin, 'team', 'delete'));

        $member = $this->resolver()->resolve($this->request('member', $id));
        self::assertTrue($this->h()->authz->allows($member, 'team', 'read'));
        self::assertFalse($this->h()->authz->allows($member, 'team', 'update'));

        $owner = $this->resolver()->resolve($this->request('owner', $id));
        self::assertTrue($this->h()->authz->allows($owner, 'team', 'delete'), 'inherits down the policy hierarchy');
        self::assertTrue($this->h()->authz->allows($owner, 'team', 'update'));
    }

    public function test_a_non_member_resolves_to_a_roleless_subject_which_the_filter_refuses_as_no_role(): void
    {
        $id = $this->team();

        $stranger = $this->resolver()->resolve($this->request('stranger', $id));

        self::assertNotNull($stranger);
        self::assertTrue($stranger->isRoleless());
        self::assertFalse($this->h()->authz->allows($stranger, 'team', 'read'));
    }

    public function test_a_removed_member_stops_resolving_to_their_role_on_the_next_request(): void
    {
        $id = $this->team();
        self::assertSame(['team:admin'], $this->resolver()->resolve($this->request('admin', $id))?->roles);

        $this->h()->as('owner')->removeMember($id, 'admin');

        self::assertTrue($this->resolver()->resolve($this->request('admin', $id))?->isRoleless());
    }

    public function test_the_same_user_under_another_tenant_holds_no_team_role(): void
    {
        $id = $this->team();

        $forged = $this->resolver(null, 'tenant-b')->resolve($this->request('admin', $id, 'tenant-b'));

        self::assertTrue($forged?->isRoleless());
    }

    public function test_a_route_without_a_team_is_left_to_the_fallback_or_to_nobody(): void
    {
        self::assertNull($this->resolver()->resolve($this->request('admin', null)), 'null = the filter behaves as if no resolver were bound');

        $seat = new class implements SubjectResolverContract {
            public function resolve(Request $request): ?Subject
            {
                return new Subject('admin', ['staff'], 'tenant-a');
            }
        };

        self::assertSame(['staff'], $this->resolver($seat)->resolve($this->request('admin', null))?->roles);
    }

    public function test_seat_roles_and_team_roles_are_merged_when_a_fallback_exists(): void
    {
        $id = $this->team();
        $seat = new class implements SubjectResolverContract {
            public function resolve(Request $request): ?Subject
            {
                return new Subject('member', ['staff'], 'tenant-a');
            }
        };

        $merged = $this->resolver($seat)->resolve($this->request('member', $id));

        self::assertSame(['staff', 'team:member'], $merged?->roles);
    }

    public function test_a_guest_resolves_to_nothing(): void
    {
        $guest = Request::build('GET', '/x')->withAttribute('route_params', ['teamId' => 'abc']);

        self::assertNull($this->resolver()->resolve($guest));
    }
}
