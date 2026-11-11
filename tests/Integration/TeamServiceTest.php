<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Integration;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use PHPUnit\Framework\TestCase;
use Plugins\Teams\Application\Exceptions\TeamException;
use Tests\Plugins\Teams\Support\FailingDatabase;
use Tests\Plugins\Teams\Support\Harness;

/**
 * Teams, memberships and prefs against the real repository, the real migrations
 * and the real Authorization policy. Every refusal asserts its REASON — the code
 * and the HTTP status — not merely that something threw.
 */
final class TeamServiceTest extends TestCase
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

    /** Run $fn and require it to be refused with exactly this code and status. */
    private function refused(callable $fn, string $code, int $status): void
    {
        try {
            $fn();
        } catch (TeamException $e) {
            self::assertSame($code, $e->errorCode, 'refused for the wrong reason');
            self::assertSame($status, $e->httpStatus());

            return;
        }

        self::fail("expected a refusal [{$code}], but the call succeeded");
    }

    /** A team owned by `owner`, with `admin` and `member` seated. */
    private function team(): string
    {
        $id = $this->h()->as('owner')->create('Platform')->id;
        $this->h()->seat($id, 'admin', ['admin']);
        $this->h()->seat($id, 'member', ['member']);

        return $id;
    }

    public function test_the_creator_becomes_the_owner_and_the_team_is_theirs(): void
    {
        $view = $this->h()->as('owner')->create('Platform', ['theme' => 'dark']);

        self::assertSame(['owner'], $view->myRoles);
        self::assertSame(1, $view->memberCount);
        self::assertSame(['theme' => 'dark'], $view->prefs);
        self::assertSame([$view->id], array_map(static fn ($t) => $t->id, $this->h()->as('owner')->mine()));
        self::assertSame(['team.created', 'team.member.added'], $this->h()->events->names());
    }

    public function test_a_guest_cannot_create_a_team(): void
    {
        $this->refused(fn () => $this->h()->as('')->create('X'), 'team.unauthenticated', 401);
        self::assertSame(0, $this->h()->count('teams'));
    }

    public function test_an_invalid_name_is_refused_and_writes_nothing(): void
    {
        $this->refused(fn () => $this->h()->as('owner')->create('   '), 'team.name.invalid', 422);
        self::assertSame(0, $this->h()->count('teams'));
        self::assertSame(0, $this->h()->count('team_memberships'));
    }

    public function test_a_non_member_cannot_see_a_team_and_cannot_tell_it_exists(): void
    {
        $id = $this->team();

        $this->refused(fn () => $this->h()->as('stranger')->find($id), 'team.not_found', 404);
        // The same answer as an id that was never issued.
        $this->refused(fn () => $this->h()->as('stranger')->find(str_repeat('0', 32)), 'team.not_found', 404);
        $this->refused(fn () => $this->h()->as('stranger')->members($id), 'team.not_found', 404);
        $this->refused(fn () => $this->h()->as('stranger')->update($id, 'Mine now'), 'team.not_found', 404);
        $this->refused(fn () => $this->h()->as('stranger')->delete($id), 'team.not_found', 404);
        self::assertSame([], $this->h()->as('stranger')->mine());
    }

    public function test_a_member_with_the_wrong_role_is_refused_with_403(): void
    {
        $id = $this->team();
        $m  = $this->h()->as('member');

        self::assertSame($id, $m->find($id)->id, 'a plain member may read');

        $this->refused(fn () => $m->update($id, 'Renamed'), 'team.forbidden', 403);
        $this->refused(fn () => $m->updatePrefs($id, ['a' => 1]), 'team.forbidden', 403);
        $this->refused(fn () => $m->invite($id, 'new@example.com'), 'team.forbidden', 403);
        $this->refused(fn () => $m->pendingInvites($id), 'team.forbidden', 403);
        $this->refused(fn () => $m->changeRoles($id, 'member', ['admin']), 'team.forbidden', 403);
        $this->refused(fn () => $m->removeMember($id, 'admin'), 'team.forbidden', 403);
        $this->refused(fn () => $m->delete($id), 'team.forbidden', 403);

        self::assertSame('Platform', $this->h()->as('owner')->find($id)->name, 'nothing was changed');
        self::assertSame(3, $this->h()->count('team_memberships'));
    }

    public function test_an_admin_manages_the_team_but_not_its_owners_or_its_existence(): void
    {
        $id = $this->team();
        $a  = $this->h()->as('admin');

        self::assertSame('Renamed', $a->update($id, 'Renamed')->name);
        self::assertSame(['x' => 1], $a->updatePrefs($id, ['x' => 1]));

        $this->refused(fn () => $a->delete($id), 'team.forbidden', 403);
        $this->refused(fn () => $a->invite($id, 'o@example.com', ['owner']), 'team.forbidden', 403);
        $this->refused(fn () => $a->changeRoles($id, 'member', ['owner']), 'team.forbidden', 403);
        $this->refused(fn () => $a->changeRoles($id, 'owner', ['member']), 'team.forbidden', 403);
        $this->refused(fn () => $a->removeMember($id, 'owner'), 'team.forbidden', 403);

        self::assertTrue($this->h()->as('owner')->find($id)->myRoles === ['owner']);
    }

    public function test_the_owner_can_delete_and_a_deleted_team_is_gone_for_everyone(): void
    {
        $id = $this->team();
        $this->h()->events->all();

        $this->h()->as('owner')->delete($id);

        self::assertContains('team.deleted', $this->h()->events->names());
        self::assertSame(0, $this->h()->count('team_memberships'), 'the seats go with the team');
        $this->refused(fn () => $this->h()->as('owner')->find($id), 'team.not_found', 404);
        $this->refused(fn () => $this->h()->as('admin')->find($id), 'team.not_found', 404);
        self::assertSame([], $this->h()->as('owner')->mine());
        self::assertSame([], $this->h()->membership()->rolesIn($id, 'owner'));
    }

    public function test_a_team_in_tenant_a_does_not_exist_in_tenant_b(): void
    {
        $id = $this->h()->as('alice', 'tenant-a')->create('A only')->id;

        // The same user id, claiming another tenant: no seat there.
        $this->refused(fn () => $this->h()->as('alice', 'tenant-b')->find($id), 'team.not_found', 404);
        self::assertSame([], $this->h()->as('alice', 'tenant-b')->mine());
        self::assertSame([], $this->h()->membership('tenant-b')->rolesIn($id, 'alice'));
        self::assertSame([], $this->h()->membership('tenant-b')->membershipsOf('alice'));

        // And what tenant B creates is invisible to A.
        $b = $this->h()->as('alice', 'tenant-b')->create('B only')->id;
        $this->refused(fn () => $this->h()->as('alice', 'tenant-a')->find($b), 'team.not_found', 404);
    }

    public function test_a_seat_in_one_tenant_grants_nothing_under_a_forged_tenant(): void
    {
        // Member in tenant A holding a token that CLAIMS tenant B: the claim is a
        // hint, the membership table is the authority, and it has no such row.
        $id = $this->team();
        $this->refused(fn () => $this->h()->as('admin', 'tenant-b')->update($id, 'Hijacked'), 'team.not_found', 404);
        self::assertSame('Platform', $this->h()->as('owner')->find($id)->name);
    }

    public function test_removing_a_member_stops_their_access_on_the_next_call(): void
    {
        $id = $this->team();
        self::assertSame($id, $this->h()->as('admin')->find($id)->id);

        $this->h()->as('owner')->removeMember($id, 'admin');

        $this->refused(fn () => $this->h()->as('admin')->find($id), 'team.not_found', 404);
        self::assertContains('team.member.removed', $this->h()->events->names());
    }

    public function test_a_role_change_takes_effect_immediately(): void
    {
        $id = $this->team();
        self::assertSame('Ok', $this->h()->as('admin')->update($id, 'Ok')->name);

        $this->h()->as('owner')->changeRoles($id, 'admin', ['member']);

        $this->refused(fn () => $this->h()->as('admin')->update($id, 'Again'), 'team.forbidden', 403);
        self::assertContains('team.member.roles_changed', $this->h()->events->names());
    }

    public function test_the_last_owner_cannot_leave_be_removed_or_be_demoted(): void
    {
        $id = $this->team();
        $o  = $this->h()->as('owner');

        $this->refused(fn () => $o->removeMember($id, 'owner'), 'team.last_owner', 409);
        $this->refused(fn () => $o->changeRoles($id, 'owner', ['admin']), 'team.last_owner', 409);
        self::assertSame(['owner'], $o->find($id)->myRoles);

        // With a second owner either may go.
        $o->changeRoles($id, 'admin', ['owner', 'admin']);
        $o->removeMember($id, 'owner');
        self::assertSame(['owner', 'admin'], $this->h()->as('admin')->find($id)->myRoles);

        // ...and now the remaining one is the last.
        $this->refused(fn () => $this->h()->as('admin')->removeMember($id, 'admin'), 'team.last_owner', 409);
    }

    public function test_any_member_may_leave_even_when_the_policy_has_no_rows_for_their_role(): void
    {
        $id = $this->team();
        $this->h()->seat($id, 'contractor', ['billing']);   // a custom role the policy knows nothing about

        $this->refused(fn () => $this->h()->as('contractor')->find($id), 'team.forbidden', 403);   // grants nothing

        $this->h()->as('contractor')->removeMember($id, 'contractor');

        self::assertSame([], $this->h()->membership()->rolesIn($id, 'contractor'));
    }

    public function test_with_no_policy_rows_every_guarded_action_is_refused(): void
    {
        $empty = tempnam(sys_get_temp_dir(), 'teams-empty-');
        file_put_contents($empty, "# no rows\n");

        try {
            $h = new Harness($empty);
            try {
                $id = $h->as('owner')->create('Closed')->id;   // creating needs no policy: there is no team yet

                $this->refused(fn () => $h->as('owner')->find($id), 'team.forbidden', 403);
                $this->refused(fn () => $h->as('owner')->delete($id), 'team.forbidden', 403);
                $this->refused(fn () => $h->as('owner')->invite($id, 'a@example.com'), 'team.forbidden', 403);
                // Leaving needs no policy; the refusal here is the last-owner RULE, not the policy.
                $this->refused(fn () => $h->as('owner')->removeMember($id, 'owner'), 'team.last_owner', 409);
            } finally {
                $h->discard();
            }
        } finally {
            @unlink($empty);
        }
    }

    public function test_a_restricted_roster_shows_a_member_only_themselves(): void
    {
        $id = $this->team();
        self::assertCount(3, $this->h()->as('member')->members($id), 'public by default');

        $this->h()->as('admin')->update($id, null, 'restricted');

        self::assertSame(['member'], array_map(static fn ($m) => $m->userId, $this->h()->as('member')->members($id)));
        self::assertCount(3, $this->h()->as('admin')->members($id));
        self::assertCount(3, $this->h()->as('owner')->members($id));
    }

    public function test_prefs_round_trip_and_are_bounded(): void
    {
        $id = $this->team();
        $a  = $this->h()->as('admin');

        $a->updatePrefs($id, ['theme' => 'dark', 'n' => [1, 2]]);
        self::assertSame(['theme' => 'dark', 'n' => [1, 2]], $this->h()->as('member')->prefs($id));

        $this->refused(fn () => $a->updatePrefs($id, ['blob' => str_repeat('x', 20000)]), 'team.prefs.too_large', 422);
        self::assertSame(['theme' => 'dark', 'n' => [1, 2]], $a->prefs($id), 'a refused write leaves the old document');
    }

    public function test_an_unknown_privacy_and_an_empty_update_are_refused(): void
    {
        $id = $this->team();

        $this->refused(fn () => $this->h()->as('admin')->update($id, null, 'secret'), 'team.privacy.invalid', 422);
        $this->refused(fn () => $this->h()->as('admin')->update($id), 'team.update.empty', 422);
    }

    public function test_a_failed_write_rolls_back_and_dispatches_no_phantom_event(): void
    {
        $failing = new FailingDatabase($this->h()->db);
        $store   = new \Plugins\Teams\Infrastructure\Persistence\TeamRepository($failing, Harness::identity('owner'));
        $svc     = $this->h()->as('owner', 'tenant-a', '', $store);

        $failing->failUpsert = true;   // the owner's seat cannot be written, after the team row was

        try {
            $svc->create('Doomed');
            self::fail('the failed write must surface');
        } catch (ServiceException $e) {
            self::assertSame('team.create.failed', $e->getMessage());
            self::assertInstanceOf(\AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException::class, $e->getPrevious());
        }

        self::assertSame(0, $this->h()->collector->count(), 'the events collected before the failed write were discarded, not left pending for the next caller');
        self::assertSame(0, $this->h()->count('teams'), 'the team row was rolled back with the seat');
        self::assertSame([], $this->h()->events->names(), 'no integration event for a write that never committed');
    }

    public function test_one_failure_does_not_poison_the_next_call(): void
    {
        $svc = $this->h()->as('owner');
        $this->refused(fn () => $svc->create(''), 'team.name.invalid', 422);

        self::assertSame(['owner'], $svc->create('Fine')->myRoles);
        self::assertSame(['team.created', 'team.member.added'], $this->h()->events->names(), 'only the committed call published');
    }

    public function test_roles_are_validated(): void
    {
        $id = $this->team();

        $this->refused(fn () => $this->h()->as('owner')->changeRoles($id, 'member', []), 'team.role.required', 422);
        $this->refused(fn () => $this->h()->as('owner')->changeRoles($id, 'member', ['Bad Role,x']), 'team.role.invalid', 422);
        $this->refused(fn () => $this->h()->as('owner')->changeRoles($id, 'ghost', ['member']), 'team.member.not_found', 404);
    }
}
