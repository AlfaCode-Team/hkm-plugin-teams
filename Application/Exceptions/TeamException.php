<?php

declare(strict_types=1);

namespace Plugins\Teams\Application\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\HttpStatusAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;

/**
 * A refusal with a stable code and the HTTP status that code means.
 *
 * The kernel's error envelope publishes `layer` as `error.code`, so the CODE
 * (`team.not_found`) is passed as the layer: a client branches on `error.code`
 * and gets a specific answer rather than the same `service.teams` for all of
 * them. The MESSAGE is the sentence from resources/lang/{locale}/teams.php under
 * `errors.<code>` when the I18n plugin is installed (so a project can translate
 * or reword it without touching this class), and the code itself otherwise.
 */
final class TeamException extends ServiceException implements HttpStatusAware
{
    /** @param array<string, mixed> $context */
    public function __construct(public readonly string $errorCode, private readonly int $status, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct(self::sentence($errorCode), layer: $errorCode, context: $context, previous: $previous);
    }

    /** `__()` belongs to the I18n plugin, not the kernel, so it is used only when present. */
    private static function sentence(string $code): string
    {
        if (!\function_exists('__')) {
            return $code;
        }

        $line = __("teams::errors.{$code}");

        return $line === "teams::errors.{$code}" ? $code : $line;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /** Not a member — and, deliberately, the same answer as "no such team". */
    public static function notFound(string $teamId): self
    {
        return new self('team.not_found', 404, ['teamId' => $teamId]);
    }

    /** A member the policy does not allow. */
    public static function forbidden(string $action): self
    {
        return new self('team.forbidden', 403, ['action' => $action]);
    }

    public static function unauthenticated(): self
    {
        return new self('team.unauthenticated', 401);
    }

    public static function conflict(string $code): self
    {
        return new self($code, 409);
    }

    public static function gone(string $code): self
    {
        return new self($code, 410);
    }

    /** A domain rule, translated from the \DomainException that carries the code. */
    public static function rule(string $code, ?\Throwable $previous = null): self
    {
        return new self($code, 422, [], $previous);
    }

    /** An invite that does not exist or whose secret is wrong — one answer for both. */
    public static function invalidInvite(): self
    {
        return new self('team.invite.invalid', 404);
    }
}
