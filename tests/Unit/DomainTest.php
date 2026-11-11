<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Teams\Domain\Invite;
use Plugins\Teams\Domain\InviteStatus;
use Plugins\Teams\Domain\Membership;
use Plugins\Teams\Domain\Team;
use Plugins\Teams\Domain\TeamRole;

final class DomainTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function badRoles(): array
    {
        return [
            'empty' => [''], 'space' => ['a b'], 'comma' => ['a,b'], 'colon' => ['team:owner'],
            'newline' => ["a\nb"], 'digit first' => ['1abc'], 'too long' => [str_repeat('a', 33)], 'unicode' => ['é'],
        ];
    }

    #[DataProvider('badRoles')]
    public function test_a_role_that_could_alter_a_policy_row_is_refused(string $role): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('team.role.invalid');
        TeamRole::of($role);
    }

    public function test_roles_are_normalised_deduplicated_and_bounded(): void
    {
        self::assertSame(['admin', 'billing'], TeamRole::values(TeamRole::listOf(['Admin', ' billing', 'ADMIN'])));
        self::assertSame('team:admin', TeamRole::of('admin')->policyName());

        $this->expectExceptionMessage('team.role.too_many');
        TeamRole::listOf(array_map(static fn (int $i): string => "r{$i}", range(1, 9)));
    }

    public function test_a_team_records_its_events_once(): void
    {
        $team = Team::create('t', 'tenant', 'Name', 'u', 10);

        self::assertSame(['created'], array_map(static fn ($e) => $e->kind, $team->releaseEvents()));
        self::assertSame([], $team->releaseEvents(), 'releasing clears the buffer');
        self::assertSame([], Team::reconstitute('t', 'tenant', 'N', \Plugins\Teams\Domain\Privacy::Members, [], 'u', 1, 1, null)->releaseEvents(), 'reconstitute records nothing');
    }

    public function test_a_team_cannot_be_deleted_twice(): void
    {
        $team = Team::create('t', 'tenant', 'Name', 'u', 10);
        $team->delete(11);

        $this->expectExceptionMessage('team.deleted');
        $team->delete(12);
    }

    public function test_an_invite_stores_a_hash_and_matches_in_constant_time_form(): void
    {
        $invite = Invite::issue('i', 't', 'team', 'A@B.co', [TeamRole::of('member')], 'secret', 'u', 100, 3600);

        self::assertNotSame('secret', $invite->tokenHash);
        self::assertTrue($invite->tokenMatches('secret'));
        self::assertFalse($invite->tokenMatches('secreT'));
        self::assertSame('a@b.co', $invite->email);
        self::assertSame(3700, $invite->expiresAt);
    }

    public function test_an_invite_moves_out_of_pending_exactly_once(): void
    {
        $invite = Invite::issue('i', 't', 'team', 'a@b.co', [TeamRole::of('member')], 's', 'u', 100, 3600);
        $invite->accept('u2', 200);

        self::assertSame(InviteStatus::Accepted, $invite->status());
        foreach ([fn () => $invite->accept('u3', 201), fn () => $invite->decline('u3', 201), fn () => $invite->revoke('u3', 201)] as $again) {
            try {
                $again();
                self::fail('a spent invite moved again');
            } catch (\DomainException $e) {
                self::assertSame('team.invite.not_pending', $e->getMessage());
            }
        }
    }

    public function test_an_expired_invite_cannot_be_accepted_even_with_the_right_secret(): void
    {
        $invite = Invite::issue('i', 't', 'team', 'a@b.co', [TeamRole::of('member')], 's', 'u', 100, 3600);

        $this->expectExceptionMessage('team.invite.expired');
        $invite->accept('u2', 3700);
    }

    public function test_a_membership_needs_a_user_and_a_role(): void
    {
        $this->expectExceptionMessage('team.member.user_required');
        Membership::join('m', 't', 'team', '', [TeamRole::of('member')], 1);
    }
}
