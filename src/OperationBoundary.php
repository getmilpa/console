<?php

/**
 * This file is part of Milpa console.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/console
 */

declare(strict_types=1);

namespace Milpa\Console;

use Milpa\Command\Operation;
use Milpa\ToolRuntime\Contracts\ToolContext;

/** Host-owned execution boundary shared by every operation surface. */
interface OperationBoundary
{
    /**
     * Execute the declared handler inside the host's authority and confinement boundary.
     *
     * @param array<string, mixed> $input
     * @param \Closure(): mixed    $next  the declared handler, callable only inside this boundary
     */
    public function execute(Operation $operation, array $input, ?ToolContext $authority, \Closure $next): mixed;
}
