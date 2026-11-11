<?php

declare(strict_types=1);

namespace Plugins\Teams\Domain;

/**
 * A role a member holds in ONE team.
 *
 * The role is only a NAME. What it may do lives in the Authorization policy,
 * where it appears as `team:<name>` — see {@see policyName()}. This class
 * therefore decides nothing about permissions, which is the point: a second
 * permission table here would be a second answer to "may they", and the two
 * would drift.
 *
 * The grammar is deliberately narrow. The policy name ends up in a Casbin CSV
 * row, where a comma, a newline or a space changes what the row means.
 */
final readonly class TeamRole
{
    public const string OWNER  = 'owner';
    public const string ADMIN  = 'admin';
    public const string MEMBER = 'member';

    /** The prefix every team role carries in the policy, so it can never collide with a seat role such as `admin`. */
    public const string POLICY_PREFIX = 'team:';

    public const int MAX_PER_MEMBER = 8;

    private function __construct(public string $value) {}

    public static function of(string $role): self
    {
        $role = strtolower(trim($role));

        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $role) !== 1) {
            throw new \DomainException('team.role.invalid');
        }

        return new self($role);
    }

    /**
     * A de-duplicated, non-empty, ordered list.
     *
     * @param  iterable<mixed> $roles
     * @return list<self>
     */
    public static function listOf(iterable $roles): array
    {
        $seen = [];
        foreach ($roles as $role) {
            if (!\is_string($role)) {
                throw new \DomainException('team.role.invalid');
            }
            $seen[self::of($role)->value] = true;
        }

        if ($seen === []) {
            throw new \DomainException('team.role.required');
        }
        if (\count($seen) > self::MAX_PER_MEMBER) {
            throw new \DomainException('team.role.too_many');
        }

        return array_map(static fn (string $r): self => new self($r), array_keys($seen));
    }

    /** @param list<self> $roles @return list<string> */
    public static function values(array $roles): array
    {
        return array_map(static fn (self $r): string => $r->value, $roles);
    }

    /** @param list<self> $roles */
    public static function contains(array $roles, string $role): bool
    {
        foreach ($roles as $candidate) {
            if ($candidate->value === $role) {
                return true;
            }
        }

        return false;
    }

    /** The name the Authorization policy knows this role by. */
    public function policyName(): string
    {
        return self::POLICY_PREFIX . $this->value;
    }
}
