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

namespace Milpa\Console\Tests;

use Milpa\Command\Operation;
use Milpa\Console\OperationPermissionPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Policy\AuthorizationResult;

/** A judge whose verdict the test sets, and which remembers what it was asked. */
final class JudgeDouble implements OperationPermissionPolicy
{
    /** @var list<array{operation: Operation, caller: ToolContext, arguments: array<string, mixed>}> */
    public array $asked = [];

    public bool $throws = false;

    public function __construct(public bool $allow)
    {
    }

    public function enforce(Operation $op, ToolContext $caller, array $arguments): AuthorizationResult
    {
        $this->asked[] = ['operation' => $op, 'caller' => $caller, 'arguments' => $arguments];
        if ($this->throws) {
            throw new \RuntimeException('the permission store is unreachable');
        }

        return $this->allow
            ? AuthorizationResult::allowed()
            : AuthorizationResult::denied("Permission '{$op->permission}' is required.");
    }
}
