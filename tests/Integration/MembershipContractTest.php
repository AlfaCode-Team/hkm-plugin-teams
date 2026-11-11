<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Plugins\Teams\Support\Harness;

/** The live membership reads other plugins build decisions from. */
final class MembershipContractTest extends TestCase
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

    public function test_roles_policy_roles_and_memberships_are_read_live(): void
    {
        $a = $this->h()->as('u1')->create('A')->id;
        $b = $this->h()->as('u2')->create('B')->id;
        $this->h()->seat($b, 'u1', ['admin', 'billing']);

        $m = $this->h()->membership();

        self::assertSame(['owner'], $m->rolesIn($a, 'u1'));
        self::assertSame(['team:admin', 'team:billing'], $m->policyRolesIn($b, 'u1'));
        $ids = array_map(static fn ($v) => $v->teamId, $m->membershipsOf('u1'));
        sort($ids);   // joined_at ties inside one second; the order is not the contract
        $expected = [$a, $b];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertSame([], $m->rolesIn($a, 'u2'));
        self::assertSame([], $m->rolesIn('', 'u1'));
        self::assertSame([], $m->membershipsOf(''));
    }

    public function test_team_existence_is_tenant_scoped_and_ends_with_deletion(): void
    {
        $a = $this->h()->as('u1')->create('A')->id;

        self::assertTrue($this->h()->membership()->teamExists($a));
        self::assertFalse($this->h()->membership('tenant-b')->teamExists($a), 'another tenant cannot see it');
        self::assertFalse($this->h()->membership()->teamExists(''));
        self::assertFalse($this->h()->membership()->teamExists(str_repeat('0', 32)));

        $this->h()->as('u1')->delete($a);
        self::assertFalse($this->h()->membership()->teamExists($a));
    }

    public function test_a_deleted_team_stops_counting_at_once(): void
    {
        $a = $this->h()->as('u1')->create('A')->id;
        self::assertCount(1, $this->h()->membership()->membershipsOf('u1'));

        $this->h()->as('u1')->delete($a);

        self::assertSame([], $this->h()->membership()->membershipsOf('u1'));
        self::assertSame([], $this->h()->membership()->rolesIn($a, 'u1'));
    }

    public function test_a_deleted_team_with_a_surviving_seat_row_still_grants_nothing(): void
    {
        // Belt and braces: even if a seat row outlived its team (a partial
        // failure, a manual edit), the join on `deleted_at IS NULL` ignores it.
        $a = $this->h()->as('u1')->create('A')->id;
        $this->h()->db->execute('UPDATE teams SET deleted_at = 1 WHERE id = :i', ['i' => $a]);

        self::assertSame(1, $this->h()->count('team_memberships'));
        self::assertSame([], $this->h()->membership()->rolesIn($a, 'u1'));
        self::assertSame([], $this->h()->membership()->membershipsOf('u1'));
    }
}
