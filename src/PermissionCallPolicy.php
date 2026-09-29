<?php

/**
 * This file is part of Milpa Console — the projection layer that turns one declared Operation into the shape each surface speaks.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/console
 */

declare(strict_types=1);

namespace Milpa\Console;

use Milpa\Command\Operation;
use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\ToolDefinition;

/**
 * The PolicyGate's call policy on a registry that serves permission-typed operations: it asks the
 * {@see OperationMcpPolicy} about each such tool, then defers to the host's own call policy.
 *
 * It judges at AUTHORIZATION time, which is where the registry requires every check to have run:
 * before the confirm gate (a caller without the permission is refused, not asked to confirm) and
 * before `tool.executing`, where a listener such as a cache may answer on the tool's behalf without
 * its callback ever running. A guard that lived only in the callback would be skipped by such a
 * listener; this one cannot be.
 *
 * The gate holds a single call policy, so this one WRAPS the host's instead of replacing it: the
 * host's policy still runs for every call this one admits, and for every tool it does not guard.
 */
final class PermissionCallPolicy implements CallPolicy
{
    /** @var array<string, Operation> tool name => the permission-typed operation it serves */
    private array $guarded = [];

    public function __construct(private readonly OperationMcpPolicy $judge, private ?CallPolicy $host = null)
    {
    }

    /** Guards one more tool: calls to it are judged against this operation's permission. */
    public function guard(string $tool, Operation $operation): void
    {
        $this->guarded[$tool] = $operation;
    }

    /** Replaces the host call policy this one defers to (the container's may be registered late). */
    public function deferTo(?CallPolicy $host): void
    {
        if ($host !== $this) {
            $this->host = $host;
        }
    }

    /**
     * Denied unless the judge admits this caller for a guarded tool; then the host decides.
     *
     * @param array<string, mixed> $arguments
     */
    public function authorize(ToolContext $context, ToolDefinition $tool, array $arguments): AuthorizationResult
    {
        $operation = $this->guarded[$tool->name] ?? null;
        if ($operation !== null) {
            $verdict = self::judge($this->judge, $operation, $context, $arguments);
            if (!$verdict->allowed) {
                return $verdict;
            }
        }

        return $this->host?->authorize($context, $tool, $arguments) ?? AuthorizationResult::allowed();
    }

    /**
     * One verdict for one call, failing closed: a judge that throws or says no without a reason
     * still refuses, with a reason the caller can read.
     *
     * @param array<string, mixed> $arguments
     */
    public static function judge(OperationMcpPolicy $judge, Operation $operation, ToolContext $caller, array $arguments): AuthorizationResult
    {
        $permission = (string) $operation->permission;

        try {
            $verdict = $judge->enforce($operation, $caller, $arguments);
        } catch (\Throwable $error) {
            return AuthorizationResult::denied("Operation '{$operation->name}' requires the permission '{$permission}' and the permission policy could not decide: {$error->getMessage()}");
        }

        if ($verdict->allowed) {
            return $verdict;
        }

        return AuthorizationResult::denied($verdict->reason ?? "Operation '{$operation->name}' requires the permission '{$permission}'.");
    }
}
