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
use Milpa\ToolRuntime\Contracts\ContextualToolHandler;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolResult;

/**
 * The callable an MCP registry invokes for a permission-typed operation: it asks the
 * {@see OperationPermissionPolicy} about this caller and this call, and only then runs the operation.
 *
 * It is the second line, not the first: {@see PermissionCallPolicy} judges the call in the
 * PolicyGate, before consent and before any `tool.executing` listener. This one stays on the
 * callable because the gate holds a single call policy that anyone can replace later, and a
 * registry that is not tool-runtime's has no gate to hold it at all — a check a later setter can
 * take away would reopen the hole without anyone noticing. Nothing takes this one off the tool
 * short of registering a different callable.
 */
final readonly class PermissionGuardedHandler implements ContextualToolHandler
{
    /** @param ContextualToolHandler $next the operation's own handler, run only for an admitted caller */
    public function __construct(
        private OperationPermissionPolicy $policy,
        private Operation $operation,
        private ContextualToolHandler $next,
    ) {
    }

    /**
     * Refuses with `FORBIDDEN` unless the policy admits this caller; a missing caller is refused
     * too, because there is nobody to judge.
     *
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments, ?ToolContext $context = null): mixed
    {
        if ($context === null) {
            return $this->refuse("Operation '{$this->operation->name}' requires the permission '{$this->operation->permission}' and the call carries no caller to judge.");
        }

        $verdict = PermissionCallPolicy::judge($this->policy, $this->operation, $context, $arguments);
        if (!$verdict->allowed) {
            return $this->refuse((string) $verdict->reason);
        }

        return ($this->next)($arguments, $context);
    }

    private function refuse(string $reason): ToolResult
    {
        return ToolResult::error($reason, null, ['code' => ToolResult::FORBIDDEN, 'permission' => $this->operation->permission]);
    }
}
