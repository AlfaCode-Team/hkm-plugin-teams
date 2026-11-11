<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Support;

use AlfacodeTeam\Ground\EventRecorder;
use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\Ground\Fakes\MigratedSqlite;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Authorization\API\Contracts\SubjectAuthorizationContract;
use Plugins\Authorization\Application\Services\SubjectAuthorizationService;
use Plugins\Authorization\Engine\Enforcer;
use Plugins\Teams\Application\Ports\TeamStore;
use Plugins\Teams\Application\Services\TeamMembershipService;
use Plugins\Teams\Application\Services\TeamService;
use Plugins\Teams\Infrastructure\Config\TeamsConfig;
use Plugins\Teams\Infrastructure\Persistence\TeamRepository;

/**
 * A real migrated SQLite database, the REAL repository and service, and the
 * REAL Authorization engine reading the bundled policy file. Nothing about who
 * may do what is faked: a wrong row in config/policy.teams.csv fails here.
 *
 * One database serves every tenant and every user, which is what lets a test
 * assert that tenant A's rows are invisible to tenant B.
 */
final class Harness
{
    public MigratedSqlite $db;
    public FrozenClock $clock;
    public EventRecorder $events;
    public EventBus $bus;
    public SubjectAuthorizationContract $authz;
    public TeamsConfig $config;
    /** Shared by every service this harness builds, as one request's collector is. */
    public DomainEventCollector $collector;
    private ?string $policy = null;

    public function __construct(?string $policyFile = null, ?TeamsConfig $config = null)
    {
        $root = \dirname(__DIR__, 2);

        $this->db     = MigratedSqlite::migrated($root . '/database/migrations', 'teams');
        $this->clock  = new FrozenClock('2026-03-05 12:00:00');
        $this->config = $config ?? new TeamsConfig();
        $this->collector = new DomainEventCollector();

        $core = new CoreContainer();
        $this->events = new EventRecorder();
        $core->instance(EventRecorder::class, $this->events);
        $this->bus = new EventBus($core);
        $this->bus->subscribe(EventBus::ALL, EventRecorder::class);

        $this->policy = $policyFile ?? $root . '/config/policy.teams.csv';
        $this->authz  = new SubjectAuthorizationService(new Enforcer(
            \dirname($root) . '/hkm-plugin-authorization/config/rbac_model.conf',
            $this->policy,
        ));
    }

    public static function identity(string $userId, string $tenant = 'tenant-a', string $email = ''): Identity
    {
        return Identity::asUser($userId, $tenant, [], [], 'jwt', '', $email);
    }

    public function repo(Identity $identity): TeamRepository
    {
        return new TeamRepository($this->db, $identity);
    }

    /** A service acting as $userId in $tenant, over the shared database. */
    public function as(string $userId, string $tenant = 'tenant-a', string $email = '', ?TeamStore $store = null): TeamService
    {
        $identity = self::identity($userId, $tenant, $email);

        return new TeamService(
            store:       $store ?? $this->repo($identity),
            transaction: new TransactionManager($this->db),
            collector:   $this->collector,
            eventBus:    $this->bus,
            identity:    $identity,
            clock:       $this->clock,
            authz:       $this->authz,
            config:      $this->config,
        );
    }

    public function membership(string $tenant = 'tenant-a'): TeamMembershipService
    {
        return new TeamMembershipService($this->repo(self::identity('probe', $tenant)));
    }

    /** Make $userId a member of $teamId with $roles, bypassing the service (arrange only). @param list<string> $roles */
    public function seat(string $teamId, string $userId, array $roles, string $tenant = 'tenant-a'): void
    {
        $this->db->execute(
            'INSERT INTO team_memberships (id, tenant_id, team_id, user_id, roles, joined_at, updated_at) VALUES (:id, :t, :team, :u, :r, 1, 1)',
            ['id' => bin2hex(random_bytes(16)), 't' => $tenant, 'team' => $teamId, 'u' => $userId, 'r' => json_encode($roles)],
        );
    }

    public function count(string $table): int
    {
        return (int) $this->db->queryOne("SELECT COUNT(*) AS n FROM {$table}")['n'];
    }

    /** How many events of this name were published for this team. */
    public function countOf(string $name, string $teamId): int
    {
        return \count(array_filter(
            $this->events->all(),
            static fn ($e): bool => $e->name() === $name && ($e->payload()['teamId'] ?? null) === $teamId,
        ));
    }

    public function discard(): void
    {
        $this->db->discard();
    }
}
