<?php

declare(strict_types=1);

namespace Plugins\Teams\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\FrameworkException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Authorization\API\Contracts\SubjectAuthorizationContract;
use Plugins\Authorization\API\Subject;
use Plugins\Teams\API\Contracts\TeamServiceContract;
use Plugins\Teams\API\IntegrationEvents\TeamIntegrationEvent;
use Plugins\Teams\API\InviteView;
use Plugins\Teams\API\IssuedInvite;
use Plugins\Teams\API\MemberView;
use Plugins\Teams\API\TeamView;
use Plugins\Teams\Application\Exceptions\TeamException;
use Plugins\Teams\Application\Ports\TeamStore;
use Plugins\Teams\Domain\Events\TeamDomainEvent;
use Plugins\Teams\Domain\Invite;
use Plugins\Teams\Domain\Membership;
use Plugins\Teams\Domain\Privacy;
use Plugins\Teams\Domain\Team;
use Plugins\Teams\Domain\TeamRole;
use Plugins\Teams\Infrastructure\Config\TeamsConfig;

/**
 * Teams, memberships and invites.
 *
 * ── WHO MAY DO WHAT ─────────────────────────────────────────────────────────
 * Not decided here. Every guarded method asks {@see SubjectAuthorizationContract}
 * — the Authorization plugin's engine — whether the caller's TEAM ROLES (read
 * live from the membership table, prefixed `team:`) may perform an action on the
 * object `team`. There is no permission table, role-to-action map or `can()` in
 * this plugin; changing what an admin may do is a policy edit.
 *
 * Two things are NOT permissions and so stay here as rules: a team never loses
 * its last owner, and an invite is single-use.
 *
 * ── THE SHAPE ───────────────────────────────────────────────────────────────
 * Authorization first, then {@see unit()}, which IS the mandated
 * beginCollection / begin / try / commit / catch(rollback + discard) sequence,
 * written once instead of nine times. Integration events are dispatched by
 * {@see publish()} AFTER unit() returns — never inside it.
 *
 * Authorization is evaluated when the call starts. The checks that guard
 * against escalation (touching an owner) are repeated INSIDE the transaction
 * after the team row is locked, so a role change that lands between the two
 * cannot be used.
 */
final class TeamService implements TeamServiceContract
{
    /** Domain-rule codes whose HTTP status is not the default 422. */
    private const array STATUS = [
        'team.invite.not_pending' => 409,
        'team.last_owner'         => 409,
        'team.already_member'     => 409,
        'team.invite.expired'     => 410,
    ];

    public function __construct(
        private readonly TeamStore $store,
        private readonly TransactionManager $transaction,
        private readonly DomainEventCollector $collector,
        private readonly EventBus $eventBus,
        private readonly Identity $identity,
        private readonly ClockPort $clock,
        private readonly SubjectAuthorizationContract $authz,
        private readonly TeamsConfig $config = new TeamsConfig(),
    ) {}

    // ── Teams ───────────────────────────────────────────────────────────────

    public function create(string $name, array $prefs = []): TeamView
    {
        $this->requireUser();

        $now = $this->clock->timestamp();

        /** @var array{Team, Membership} $made */
        $made = $this->unit('team.create.failed', function () use ($name, $prefs, $now): array {
            $team = Team::create(Team::newId(), $this->identity->tenantId, $name, $this->identity->userId, $now);
            if ($prefs !== []) {
                $team->replacePrefs($prefs, $this->config->maxPrefsBytes, $now);
            }
            $owner = Membership::join(
                Membership::newId(), $team->tenantId, $team->id, $this->identity->userId,
                [TeamRole::of(TeamRole::OWNER)], $now,
            );

            // Collected BEFORE the writes, as the mandated shape has it: if a write
            // fails the events are already pending, which is exactly the case
            // discard() exists for.
            $this->collect($team->releaseEvents());
            $this->collect($owner->releaseEvents());
            $this->store->insertTeam($team);
            $this->store->insertMembership($owner);

            return [$team, $owner];
        });

        $this->publish($now);

        return $this->view($made[0], $made[1], true);
    }

    public function find(string $teamId): TeamView
    {
        $mine = $this->authorize($teamId, 'read');

        return $this->view($this->team($teamId), $mine, false);
    }

    public function mine(): array
    {
        $this->requireUser();

        $seats = [];
        foreach ($this->store->membershipsOfUser($this->identity->userId) as $seat) {
            $seats[$seat->teamId] = $seat;
        }

        $views = [];
        foreach ($this->store->teamsOfUser($this->identity->userId) as $team) {
            if (isset($seats[$team->id])) {
                $views[] = $this->view($team, $seats[$team->id], false);
            }
        }

        return $views;
    }

    public function update(string $teamId, ?string $name = null, ?string $privacy = null): TeamView
    {
        $mine = $this->authorize($teamId, 'update');
        $now  = $this->clock->timestamp();

        $team = $this->unit('team.update.failed', function () use ($teamId, $name, $privacy, $now): Team {
            if ($name === null && $privacy === null) {
                throw new \DomainException('team.update.empty');
            }
            $team = $this->team($teamId);

            if ($name !== null) {
                $team->rename($name, $now);
            }
            if ($privacy !== null) {
                $team->restrictRoster(Privacy::tryFrom($privacy) ?? throw new \DomainException('team.privacy.invalid'), $now);
            }
            $this->store->saveTeam($team);

            return $team;
        });

        return $this->view($team, $mine, false);
    }

    public function delete(string $teamId): void
    {
        $this->authorize($teamId, 'delete');
        $now = $this->clock->timestamp();

        $this->unit('team.delete.failed', function () use ($teamId, $now): void {
            $team = $this->team($teamId);
            $team->delete($now);

            $this->store->saveTeam($team);
            $this->store->deleteMembershipsOfTeam($teamId);
            $this->collect($team->releaseEvents());
        });

        $this->publish($now);
    }

    public function prefs(string $teamId): array
    {
        $this->authorize($teamId, 'read');

        return $this->team($teamId)->prefs();
    }

    public function updatePrefs(string $teamId, array $prefs): array
    {
        $this->authorize($teamId, 'update');
        $now = $this->clock->timestamp();

        return $this->unit('team.prefs.failed', function () use ($teamId, $prefs, $now): array {
            $team = $this->team($teamId);
            $team->replacePrefs($prefs, $this->config->maxPrefsBytes, $now);
            $this->store->saveTeam($team);

            return $team->prefs();
        });
    }

    // ── Members ─────────────────────────────────────────────────────────────

    public function members(string $teamId): array
    {
        $mine = $this->authorize($teamId, 'read');

        $everyone = $this->team($teamId)->privacy() === Privacy::Members || $this->mayDo($mine, 'members.read');
        $seats    = $everyone ? $this->store->membershipsOfTeam($teamId) : [$mine];

        return array_map($this->member(...), $seats);
    }

    public function changeRoles(string $teamId, string $userId, array $roles): MemberView
    {
        $actor = $this->authorize($teamId, 'members.update');
        $now   = $this->clock->timestamp();

        $target = $this->unit('team.member.update.failed', function () use ($teamId, $userId, $roles, $actor, $now): Membership {
            $new = TeamRole::listOf($roles);

            // The lock first, THEN the read the decision is made on.
            $this->store->lockTeam($teamId, $now);
            $target = $this->store->findMembership($teamId, $userId) ?? throw new TeamException('team.member.not_found', 404);

            if (($target->isOwner() || TeamRole::contains($new, TeamRole::OWNER)) && !$this->mayDo($actor, 'owners.manage')) {
                throw TeamException::forbidden('owners.manage');
            }
            if ($target->isOwner() && !TeamRole::contains($new, TeamRole::OWNER) && $this->ownerCount($teamId) <= 1) {
                throw new \DomainException('team.last_owner');
            }

            $target->changeRoles($new, $now);
            $this->store->saveMembership($target);
            $this->collect($target->releaseEvents());

            return $target;
        });

        $this->publish($now);

        return $this->member($target);
    }

    public function removeMember(string $teamId, string $userId): void
    {
        // Membership only: leaving needs no policy, so a role the policy gives
        // no rows to can still walk out. Removing SOMEONE ELSE is checked below.
        $actor = $this->membership($teamId);
        $self  = $userId === $this->identity->userId;

        if (!$self && !$this->mayDo($actor, 'members.remove')) {
            throw TeamException::forbidden('members.remove');
        }

        $now = $this->clock->timestamp();

        $this->unit('team.member.remove.failed', function () use ($teamId, $userId, $actor, $self, $now): void {
            $this->store->lockTeam($teamId, $now);
            $target = $this->store->findMembership($teamId, $userId) ?? throw new TeamException('team.member.not_found', 404);

            if (!$self && $target->isOwner() && !$this->mayDo($actor, 'owners.manage')) {
                throw TeamException::forbidden('owners.manage');
            }
            if ($target->isOwner() && $this->ownerCount($teamId) <= 1) {
                throw new \DomainException('team.last_owner');
            }

            $this->store->deleteMembership($teamId, $userId);
            $target->recordRemoval();
            $this->collect($target->releaseEvents());
        });

        $this->publish($now);
    }

    // ── Invites ─────────────────────────────────────────────────────────────

    public function invite(string $teamId, string $email, array $roles = ['member']): IssuedInvite
    {
        $actor = $this->authorize($teamId, 'invite');
        $now   = $this->clock->timestamp();

        /** @var array{Invite, string} $issued */
        $issued = $this->unit('team.invite.failed', function () use ($teamId, $email, $roles, $actor, $now): array {
            $team  = $this->team($teamId);
            $roles = TeamRole::listOf($roles);

            if (TeamRole::contains($roles, TeamRole::OWNER) && !$this->mayDo($actor, 'owners.manage')) {
                throw TeamException::forbidden('owners.manage');
            }

            $address = Invite::normalizeEmail($email);
            if ($this->store->hasPendingInviteFor($team->id, $address, $now)) {
                throw TeamException::conflict('team.invite.already_pending');
            }

            $token  = Invite::newToken();
            $invite = Invite::issue(
                Invite::newId(), $team->tenantId, $team->id, $address, $roles, $token,
                $this->identity->userId, $now, $this->config->inviteTtlSeconds,
            );

            $this->store->insertInvite($invite);
            $this->collect($invite->releaseEvents());

            return [$invite, $token];
        });

        $this->publish($now);

        return new IssuedInvite($this->inviteView($issued[0]), $issued[1]);
    }

    public function pendingInvites(string $teamId): array
    {
        $this->authorize($teamId, 'invite');

        return array_map($this->inviteView(...), $this->store->pendingInvites($teamId, $this->clock->timestamp()));
    }

    public function revokeInvite(string $teamId, string $inviteId): void
    {
        $this->authorize($teamId, 'invite');
        $now = $this->clock->timestamp();

        $this->unit('team.invite.revoke.failed', function () use ($teamId, $inviteId, $now): void {
            $invite = $this->store->findInvite($inviteId);
            if ($invite === null || $invite->teamId !== $teamId) {
                throw TeamException::invalidInvite();
            }

            $invite->revoke($this->identity->userId, $now);
            if (!$this->store->resolveInvite($invite)) {
                throw TeamException::conflict('team.invite.not_pending');
            }
            $this->collect($invite->releaseEvents());
        });

        $this->publish($now);
    }

    public function acceptInvite(string $inviteId, string $token): MemberView
    {
        $this->requireUser();
        $invite = $this->openInvite($inviteId, $token);
        $now    = $this->clock->timestamp();

        $seat = $this->unit('team.invite.accept.failed', function () use ($inviteId, $invite, $now): Membership {
            $this->store->lockTeam($invite->teamId, $now);

            // Re-read under the lock: this is the copy the decision is made on.
            $invite = $this->store->findInvite($inviteId) ?? throw TeamException::invalidInvite();
            $invite->accept($this->identity->userId, $now);

            // Conditional write: of two accepts that both got this far, one matches.
            if (!$this->store->resolveInvite($invite)) {
                throw TeamException::conflict('team.invite.not_pending');
            }

            $seat = Membership::join(
                Membership::newId(), $invite->tenantId, $invite->teamId, $this->identity->userId, $invite->roles, $now,
            );
            $this->collect($invite->releaseEvents());
            $this->collect($seat->releaseEvents());

            if (!$this->store->insertMembership($seat)) {
                throw TeamException::conflict('team.already_member');
            }

            return $seat;
        });

        $this->publish($now);

        return $this->member($seat);
    }

    public function declineInvite(string $inviteId, string $token): void
    {
        $this->requireUser();
        $this->openInvite($inviteId, $token);
        $now = $this->clock->timestamp();

        $this->unit('team.invite.decline.failed', function () use ($inviteId, $now): void {
            $invite = $this->store->findInvite($inviteId) ?? throw TeamException::invalidInvite();
            $invite->decline($this->identity->userId, $now);

            if (!$this->store->resolveInvite($invite)) {
                throw TeamException::conflict('team.invite.not_pending');
            }
            $this->collect($invite->releaseEvents());
        });

        $this->publish($now);
    }

    // ── Authorization ───────────────────────────────────────────────────────

    /**
     * The caller's membership, if the policy lets their roles do `$action`.
     *
     * 404 for a non-member (indistinguishable from a missing team — a stranger
     * learns nothing about which ids exist), 403 for a member the policy refuses.
     */
    private function authorize(string $teamId, string $action): Membership
    {
        $seat = $this->membership($teamId);

        if (!$this->mayDo($seat, $action)) {
            throw TeamException::forbidden($action);
        }

        return $seat;
    }

    /** The caller's own seat, or 404. No policy is consulted. */
    private function membership(string $teamId): Membership
    {
        $this->requireUser();

        return $this->store->findMembership($teamId, $this->identity->userId) ?? throw TeamException::notFound($teamId);
    }

    /** The one place a decision is asked for: the Authorization policy, for the member's team roles. */
    private function mayDo(Membership $seat, string $action): bool
    {
        $subject = new Subject(
            $seat->userId,
            array_map(static fn (TeamRole $r): string => $r->policyName(), $seat->roles()),
            $this->identity->tenantId,
        );

        return $this->authz->allows($subject, 'team', $action);
    }

    private function requireUser(): void
    {
        if ($this->identity->isGuest() || $this->identity->tenantId === '') {
            throw TeamException::unauthenticated();
        }
    }

    /** An invite the caller can act on: exists, secret matches, wrong-account check, team alive. */
    private function openInvite(string $inviteId, string $token): Invite
    {
        $invite = $this->store->findInvite($inviteId);

        // Missing and wrong-secret are ONE answer, so ids cannot be probed.
        if ($invite === null || !$invite->tokenMatches($token)) {
            throw TeamException::invalidInvite();
        }
        if ($this->store->findTeam($invite->teamId) === null) {
            throw TeamException::invalidInvite();
        }
        if ($this->config->requireInviteEmailMatch) {
            $mine = strtolower(trim($this->identity->email));
            if ($mine === '' || $mine !== $invite->email) {
                throw new TeamException('team.invite.wrong_account', 403);
            }
        }

        return $invite;
    }

    // ── Plumbing ────────────────────────────────────────────────────────────

    /**
     * The mandated shape, once: collect, transact, and on failure roll back AND
     * discard so a failed write can never leave a phantom event behind.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function unit(string $failCode, \Closure $work): mixed
    {
        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $result = $work();
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();

            throw $this->translate($e, $failCode);
        }

        return $result;
    }

    /** Integration events, AFTER the commit. */
    private function publish(int $now): void
    {
        $at = (new \DateTimeImmutable('@' . $now))->format(\DateTimeInterface::RFC3339);

        foreach ($this->collector->release() as $event) {
            if ($event instanceof TeamDomainEvent) {
                $this->eventBus->dispatch(new TeamIntegrationEvent($event->kind, $event->teamId, $this->identity->tenantId, $at, $event->data));
            }
        }
    }

    /** @param list<TeamDomainEvent> $events */
    private function collect(array $events): void
    {
        foreach ($events as $event) {
            $this->collector->collect($event);
        }
    }

    private function translate(\Throwable $e, string $failCode): \Throwable
    {
        if ($e instanceof \DomainException) {
            return new TeamException($e->getMessage(), self::STATUS[$e->getMessage()] ?? 422, [], $e);   // a domain rule's message IS its code
        }
        if ($e instanceof FrameworkException && !$e instanceof ServiceException) {
            return new ServiceException($failCode, layer: 'service.teams', previous: $e);
        }
        if ($e instanceof ServiceException) {
            return $e;
        }

        return new ServiceException($failCode, layer: 'service.teams', previous: $e);
    }

    private function team(string $teamId): Team
    {
        return $this->store->findTeam($teamId) ?? throw TeamException::notFound($teamId);
    }

    private function ownerCount(string $teamId): int
    {
        return \count(array_filter(
            $this->store->membershipsOfTeam($teamId),
            static fn (Membership $m): bool => $m->isOwner(),
        ));
    }

    private function view(Team $team, Membership $mine, bool $withPrefs): TeamView
    {
        return new TeamView(
            $team->id, $team->name(), $team->privacy()->value, $team->createdAt, $team->updatedAt(),
            TeamRole::values($mine->roles()), $this->store->countMembers($team->id), $withPrefs ? $team->prefs() : [],
        );
    }

    private function member(Membership $m): MemberView
    {
        return new MemberView($m->userId, TeamRole::values($m->roles()), $m->joinedAt);
    }

    private function inviteView(Invite $i): InviteView
    {
        return new InviteView($i->id, $i->teamId, $i->email, TeamRole::values($i->roles), $i->status()->value, $i->expiresAt, $i->createdAt, $i->invitedBy);
    }
}
