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
 * Decides whether a caller identified by a {@see ToolContext} may run an operation typed by
 * `permission` — the counterpart of {@see \Milpa\Command\OperationHttpPolicy} for the surfaces that
 * carry a ToolContext: MCP, and the CLI when its caller is finite.
 *
 * An operation declares its authority as `scopes` XOR `permission`. Scopes are judged by
 * tool-runtime's PolicyGate; a permission is a semantic key (`{namespace}.{resource}:{action}`) that
 * only an identity layer can resolve, so each surface asks the host for this policy and consults it
 * on EVERY call of a permission-typed operation, before the handler runs. A host registers its
 * implementation in the container under this interface.
 *
 * Not knowing is not allowing. On MCP, with no policy registered, {@see McpProjector} does not serve
 * a permission-typed operation at all — it withholds it and says so; a wildcard scope (`*`, what a
 * process-trusted stdio caller holds) is not a permission there either. On the CLI,
 * {@see CliRunner} refuses a FINITE caller — one whose authority does not hold `*`, such as an
 * enrolled seat or a scoped token — when there is no policy to judge it; the local shell, which holds
 * the terminal's wildcard, is not asked.
 */
interface OperationPermissionPolicy
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
