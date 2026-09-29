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
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Policy\AuthorizationResult;

/**
 * Decides whether an MCP caller may run an operation typed by `permission` — the MCP counterpart of
 * {@see \Milpa\Command\OperationHttpPolicy}.
 *
 * An operation declares its authority as `scopes` XOR `permission`. Scopes travel into the tool
 * registry and tool-runtime's PolicyGate judges them; a permission is a semantic key
 * (`{namespace}.{resource}:{action}`) that only an identity layer can resolve, so the projector
 * asks the host for this policy and consults it on EVERY call of a permission-typed tool, before
 * the handler runs. A host registers its implementation in the container under this interface
 * (the one `milpa/auth` resolves permissions with, for example).
 *
 * Not knowing is not allowing: when no policy is registered, {@see McpProjector} does not serve a
 * permission-typed operation at all — it withholds it and says so — instead of serving it unjudged.
 * A wildcard scope (`*`, what a process-trusted stdio caller holds) is not a permission either; only
 * this policy says yes.
 */
interface OperationMcpPolicy
{
    /**
     * The verdict for one concrete call: allowed, or denied with the reason the caller reads.
     *
     * Throwing refuses the call too — a judge that cannot answer is not a yes.
     *
     * @param Operation            $op        the permission-typed operation being called
     * @param ToolContext          $caller    who the registry says is calling
     * @param array<string, mixed> $arguments the validated call input
     */
    public function enforce(Operation $op, ToolContext $caller, array $arguments): AuthorizationResult;
}
