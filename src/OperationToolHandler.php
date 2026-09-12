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
use Milpa\ToolRuntime\Contracts\ContextualToolHandler;
use Milpa\ToolRuntime\Contracts\ToolContext;

/** Carries the registry's verified caller into the common operation executor. */
final readonly class OperationToolHandler implements ContextualToolHandler
{
    public function __construct(private OperationRunner $runner, private Operation $operation)
    {
    }

    /** @param array<string, mixed> $arguments */
    public function __invoke(array $arguments, ?ToolContext $context = null): mixed
    {
        return $this->runner->run($this->operation, $arguments, 'mcp', authority: $context);
    }
}
