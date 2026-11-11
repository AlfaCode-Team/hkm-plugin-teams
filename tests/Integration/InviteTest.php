<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Integration;

use PHPUnit\Framework\TestCase;
use Plugins\Teams\Application\Exceptions\TeamException;
use Plugins\Teams\Domain\Invite;
use Plugins\Teams\Domain\InviteStatus;
use Plugins\Teams\Infrastructure\Config\TeamsConfig;
use Tests\Plugins\Teams\Support\Harness;

/** The invite lifecycle: issue, accept once, decline, revoke, expire — each refusal with its reason. */
final class InviteTest extends TestCase
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

    private function team(): string
    {
        $id = $this->h()->as('owner')->create('Platform')->id;
        $this->h()->seat($id, 'admin', ['admin']);
        $this->h()->seat($id, 'member', ['member']);

        return $id;
    }

    public function test_an_invite_is_accepted_by_its_addressee_and_seats_them_with_the_invited_roles(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'Jane@Example.com', ['member', 'billing']);

        self::assertSame('jane@example.com', $issued->invite->email, 'addresses are normalised');
        self::assertSame(48, \strlen($issued->token));

        $seat = $this->h()->as('jane', 'tenant-a', 'jane@example.com')->acceptInvite($issued->invite->id, $issued->token);

        self::assertSame(['member', 'billing'], $seat->roles);
        self::assertSame(['member', 'billing'], $this->h()->membership()->rolesIn($id, 'jane'));
        self::assertContains('team.invite.accepted', $this->h()->events->names());
        self::assertSame([], $this->h()->as('admin')->pendingInvites($id), 'an accepted invite is no longer pending');
    }

    public function test_an_invite_cannot_be_accepted_twice(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'jane@example.com');
        $jane   = $this->h()->as('jane', 'tenant-a', 'jane@example.com');

        $jane->acceptInvite($issued->invite->id, $issued->token);
        $accepted = $this->h()->count('team_memberships');

        // The same person again...
        $this->refused(fn () => $jane->acceptInvite($issued->invite->id, $issued->token), 'team.invite.not_pending', 409);
        // ...and someone else who got hold of the link and the address.
        $this->refused(
            fn () => $this->h()->as('janes-other-account', 'tenant-a', 'jane@example.com')->acceptInvite($issued->invite->id, $issued->token),
            'team.invite.not_pending',
            409,
        );

        self::assertSame($accepted, $this->h()->count('team_memberships'), 'no second seat appeared');
        // owner at creation + jane once; the refused accepts published nothing.
        self::assertSame(2, $this->h()->countOf('team.member.added', $id));
        self::assertSame(1, (int) $this->h()->db->queryOne("SELECT COUNT(*) AS n FROM team_memberships WHERE user_id = 'jane'")['n']);
    }

    public function test_two_accepts_that_both_read_pending_cannot_both_win(): void
    {
        // The race itself: both callers loaded the invite while it was pending,
        // both passed the in-memory check, both reach the write. The conditional
        // UPDATE is what lets exactly one through.
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'jane@example.com');
        $repo   = $this->h()->repo(Harness::identity('jane', 'tenant-a'));

        $first  = $repo->findInvite($issued->invite->id);
        $second = $repo->findInvite($issued->invite->id);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $first->accept('jane', $this->h()->clock->timestamp());
        $second->accept('jane', $this->h()->clock->timestamp());

        self::assertTrue($repo->resolveInvite($first));
        self::assertFalse($repo->resolveInvite($second), 'the loser must be told, not silently succeed');
        self::assertSame('accepted', $this->h()->db->queryOne('SELECT status FROM team_invites')['status']);
    }

    public function test_the_database_also_refuses_a_second_seat_for_one_person(): void
    {
        $id = $this->team();
        $repo = $this->h()->repo(Harness::identity('admin', 'tenant-a'));
        $existing = $repo->findMembership($id, 'member');
        self::assertNotNull($existing);

        $dupe = \Plugins\Teams\Domain\Membership::join('f' . substr($existing->id, 1), 'tenant-a', $id, 'member', $existing->roles(), 5);

        self::assertFalse($repo->insertMembership($dupe));
        self::assertSame(3, $this->h()->count('team_memberships'));
    }

    public function test_a_wrong_secret_is_the_same_answer_as_a_missing_invite(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'jane@example.com');
        $jane   = $this->h()->as('jane', 'tenant-a', 'jane@example.com');

        $this->refused(fn () => $jane->acceptInvite($issued->invite->id, str_repeat('0', 48)), 'team.invite.invalid', 404);
        $this->refused(fn () => $jane->acceptInvite(str_repeat('0', 32), $issued->token), 'team.invite.invalid', 404);
        $this->refused(fn () => $jane->acceptInvite($issued->invite->id, ''), 'team.invite.invalid', 404);
        self::assertSame([], $this->h()->membership()->rolesIn($id, 'jane'));
    }

    public function test_an_invite_cannot_be_redeemed_from_an_account_with_another_address(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'jane@example.com');

        $this->refused(fn () => $this->h()->as('eve', 'tenant-a', 'eve@example.com')->acceptInvite($issued->invite->id, $issued->token), 'team.invite.wrong_account', 403);
        $this->refused(fn () => $this->h()->as('anon', 'tenant-a', '')->acceptInvite($issued->invite->id, $issued->token), 'team.invite.wrong_account', 403);
        self::assertSame([], $this->h()->membership()->rolesIn($id, 'eve'));
    }

    public function test_the_address_check_can_be_switched_off_and_the_secret_alone_then_suffices(): void
    {
        $h = new Harness(null, new TeamsConfig(requireInviteEmailMatch: false));
        try {
            $id = $h->as('owner')->create('T')->id;
            $issued = $h->as('owner')->invite($id, 'jane@example.com');

            self::assertSame(['member'], $h->as('eve', 'tenant-a', '')->acceptInvite($issued->invite->id, $issued->token)->roles);
        } finally {
            $h->discard();
        }
    }

    public function test_an_invite_from_another_tenant_does_not_exist(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'jane@example.com');

        $this->refused(
            fn () => $this->h()->as('jane', 'tenant-b', 'jane@example.com')->acceptInvite($issued->invite->id, $issued->token),
            'team.invite.invalid',
            404,
        );
    }

    public function test_an_expired_invite_is_refused_with_410(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'jane@example.com');

        $this->h()->clock->travel('+8 days');

        $this->refused(fn () => $this->h()->as('jane', 'tenant-a', 'jane@example.com')->acceptInvite($issued->invite->id, $issued->token), 'team.invite.expired', 410);
        self::assertSame([], $this->h()->as('admin')->pendingInvites($id), 'an expired invite is not listed as pending');
        self::assertSame([], $this->h()->membership()->rolesIn($id, 'jane'));
    }

    public function test_a_revoked_invite_cannot_be_accepted(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'jane@example.com');

        $this->h()->as('admin')->revokeInvite($id, $issued->invite->id);

        $this->refused(fn () => $this->h()->as('jane', 'tenant-a', 'jane@example.com')->acceptInvite($issued->invite->id, $issued->token), 'team.invite.not_pending', 409);
        $this->refused(fn () => $this->h()->as('admin')->revokeInvite($id, $issued->invite->id), 'team.invite.not_pending', 409);
        self::assertContains('team.invite.revoked', $this->h()->events->names());
    }

    public function test_an_invite_to_a_deleted_team_cannot_be_accepted(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'jane@example.com');

        $this->h()->as('owner')->delete($id);

        $this->refused(fn () => $this->h()->as('jane', 'tenant-a', 'jane@example.com')->acceptInvite($issued->invite->id, $issued->token), 'team.invite.invalid', 404);
        $this->refused(fn () => $this->h()->as('jane', 'tenant-a', 'jane@example.com')->declineInvite($issued->invite->id, $issued->token), 'team.invite.invalid', 404);
        self::assertSame(0, $this->h()->count('team_memberships'), 'no seat was created in a deleted team');
    }

    public function test_a_declined_invite_is_spent(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'jane@example.com');
        $jane   = $this->h()->as('jane', 'tenant-a', 'jane@example.com');

        $jane->declineInvite($issued->invite->id, $issued->token);

        $this->refused(fn () => $jane->acceptInvite($issued->invite->id, $issued->token), 'team.invite.not_pending', 409);
        $this->refused(fn () => $jane->declineInvite($issued->invite->id, $issued->token), 'team.invite.not_pending', 409);
        self::assertContains('team.invite.declined', $this->h()->events->names());
    }

    public function test_inviting_needs_the_invite_permission_and_owner_roles_need_more(): void
    {
        $id = $this->team();

        $this->refused(fn () => $this->h()->as('member')->invite($id, 'a@example.com'), 'team.forbidden', 403);
        $this->refused(fn () => $this->h()->as('admin')->invite($id, 'a@example.com', ['owner']), 'team.forbidden', 403);
        self::assertSame(0, $this->h()->count('team_invites'), 'nothing was written by a refused call');

        self::assertSame(['owner'], $this->h()->as('owner')->invite($id, 'a@example.com', ['owner'])->invite->roles);
    }

    public function test_a_second_open_invite_for_one_address_is_refused_until_the_first_is_spent(): void
    {
        $id = $this->team();
        $first = $this->h()->as('admin')->invite($id, 'jane@example.com');

        $this->refused(fn () => $this->h()->as('admin')->invite($id, 'JANE@example.com'), 'team.invite.already_pending', 409);

        $this->h()->as('admin')->revokeInvite($id, $first->invite->id);
        self::assertNotSame($first->invite->id, $this->h()->as('admin')->invite($id, 'jane@example.com')->invite->id);
    }

    public function test_an_invite_for_a_member_who_already_has_a_seat_is_refused_on_accept(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'member@example.com');

        $this->refused(fn () => $this->h()->as('member', 'tenant-a', 'member@example.com')->acceptInvite($issued->invite->id, $issued->token), 'team.already_member', 409);
        self::assertSame('pending', $this->h()->db->queryOne('SELECT status FROM team_invites')['status'], 'the failed accept rolled back with it');
    }

    public function test_an_invalid_address_is_refused(): void
    {
        $id = $this->team();

        $this->refused(fn () => $this->h()->as('admin')->invite($id, 'not-an-email'), 'team.invite.email_invalid', 422);
    }

    public function test_the_secret_is_never_stored_and_never_published(): void
    {
        $id     = $this->team();
        $issued = $this->h()->as('admin')->invite($id, 'jane@example.com');

        $dump = json_encode($this->h()->db->query('SELECT * FROM team_invites'));
        self::assertStringNotContainsString($issued->token, (string) $dump, 'the database holds only a hash');
        self::assertStringContainsString(Invite::hashToken($issued->token), (string) $dump);

        foreach ($this->h()->events->all() as $event) {
            self::assertStringNotContainsString($issued->token, json_encode($event->payload()), $event->name() . ' leaked the secret');
        }

        $listed = $this->h()->as('admin')->pendingInvites($id)[0]->toArray();
        self::assertArrayNotHasKey('token', $listed);
    }

    public function test_pending_invites_are_scoped_to_their_team(): void
    {
        $a = $this->team();
        $b = $this->h()->as('owner')->create('Other')->id;
        $this->h()->as('admin')->invite($a, 'one@example.com');
        $this->h()->as('owner')->invite($b, 'two@example.com');

        self::assertSame(['one@example.com'], array_map(static fn ($i) => $i->email, $this->h()->as('admin')->pendingInvites($a)));

        // An admin of A cannot revoke B's invite by naming it under A.
        $invB = $this->h()->as('owner')->pendingInvites($b)[0]->id;
        $this->refused(fn () => $this->h()->as('admin')->revokeInvite($a, $invB), 'team.invite.invalid', 404);
        self::assertSame(InviteStatus::Pending->value, $this->h()->db->queryOne('SELECT status FROM team_invites WHERE id = :i', ['i' => $invB])['status']);
    }
}
