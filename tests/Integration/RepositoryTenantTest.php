<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Integration;

use PHPUnit\Framework\TestCase;
use Plugins\Teams\Domain\Invite;
use Plugins\Teams\Domain\InviteStatus;
use Plugins\Teams\Domain\Membership;
use Plugins\Teams\Domain\Privacy;
use Plugins\Teams\Domain\Team;
use Plugins\Teams\Domain\TeamRole;
use Tests\Plugins\Teams\Support\Harness;

/**
 * The repository, driven DIRECTLY, from tenant B, against tenant A's rows.
 *
 * The service tests cannot show every `tenant_id` filter: most statements are
 * reached through a lookup that is itself tenant-scoped, so removing a filter
 * from the second statement changes nothing the first did not already hide. Here
 * each statement is asked, on its own, with the SAME ids and user as tenant A's
 * data — the collision that makes a missing filter visible.
 */
final class RepositoryTenantTest extends TestCase
{
    private ?Harness $h = null;
    private string $team = '';
    private string $inviteId = '';

    protected function setUp(): void
    {
        $this->h = new Harness();

        $a          = $this->h->as('alice', 'tenant-a', 'alice@example.com');
        $this->team = $a->create('Platform')->id;
        $this->inviteId = $a->invite($this->team, 'jane@example.com')->invite->id;
    }

    protected function tearDown(): void
    {
        $this->h?->discard();
    }

    private function h(): Harness
    {
        return $this->h ?? throw new \LogicException('not set up');
    }

    private function row(string $sql): array
    {
        return $this->h()->db->queryOne($sql) ?? [];
    }

    public function test_reads_from_another_tenant_find_nothing(): void
    {
        $b = $this->h()->repo(Harness::identity('alice', 'tenant-b'));

        self::assertNull($b->findTeam($this->team));
        self::assertSame([], $b->teamsOfUser('alice'));
        self::assertNull($b->findMembership($this->team, 'alice'));
        self::assertSame([], $b->membershipsOfTeam($this->team));
        self::assertSame([], $b->membershipsOfUser('alice'));
        self::assertSame(0, $b->countMembers($this->team));
        self::assertNull($b->findInvite($this->inviteId));
        self::assertSame([], $b->pendingInvites($this->team, $this->h()->clock->timestamp()));
        self::assertFalse($b->hasPendingInviteFor($this->team, 'jane@example.com', $this->h()->clock->timestamp()));

        // ...and tenant A still sees all of it.
        $a = $this->h()->repo(Harness::identity('alice', 'tenant-a'));
        self::assertNotNull($a->findTeam($this->team));
        self::assertSame(1, $a->countMembers($this->team));
        self::assertTrue($a->hasPendingInviteFor($this->team, 'jane@example.com', $this->h()->clock->timestamp()));
    }

    public function test_writes_from_another_tenant_change_nothing_in_this_one(): void
    {
        $b   = $this->h()->repo(Harness::identity('alice', 'tenant-b'));
        $now = 99999;

        // Tenant-B entities carrying tenant A's ids: the collision.
        $team = Team::reconstitute($this->team, 'tenant-b', 'HIJACKED', Privacy::Restricted, ['x' => 1], 'alice', 1, $now, null);
        $b->saveTeam($team);
        $b->lockTeam($this->team, $now);

        $seat = Membership::reconstitute('m', 'tenant-b', $this->team, 'alice', [TeamRole::of('member')], 1, $now);
        $b->saveMembership($seat);

        self::assertSame('Platform', $this->row("SELECT name FROM teams WHERE id = '{$this->team}'")['name']);
        self::assertSame('members', $this->row("SELECT privacy FROM teams WHERE id = '{$this->team}'")['privacy']);
        self::assertNotSame($now, (int) $this->row("SELECT updated_at FROM teams WHERE id = '{$this->team}'")['updated_at'], 'tenant B took tenant A\'s row lock');
        self::assertSame('["owner"]', $this->row("SELECT roles FROM team_memberships WHERE team_id = '{$this->team}'")['roles'], 'tenant B demoted tenant A\'s owner');

        self::assertFalse($b->deleteMembership($this->team, 'alice'));
        self::assertSame(0, $b->deleteMembershipsOfTeam($this->team));
        self::assertSame(1, (int) $this->row('SELECT COUNT(*) AS n FROM team_memberships')['n'], 'tenant B deleted tenant A\'s seat');
    }

    public function test_an_invite_cannot_be_resolved_from_another_tenant(): void
    {
        $b = $this->h()->repo(Harness::identity('jane', 'tenant-b'));

        $theirs = Invite::reconstitute(
            $this->inviteId, 'tenant-b', $this->team, 'jane@example.com', [TeamRole::of('member')],
            str_repeat('a', 64), 'alice', InviteStatus::Accepted, 9999999999, 1, 5, 'jane',
        );

        self::assertFalse($b->resolveInvite($theirs));
        self::assertSame('pending', $this->row("SELECT status FROM team_invites WHERE id = '{$this->inviteId}'")['status']);
    }

    public function test_an_entity_of_one_tenant_cannot_be_written_through_another_tenants_repository(): void
    {
        $b    = $this->h()->repo(Harness::identity('alice', 'tenant-b'));
        $team = Team::create('t', 'tenant-a', 'X', 'alice', 1);

        $this->expectException(\AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException::class);
        $b->insertTeam($team);
    }
}
