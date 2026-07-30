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

/**
 * Raised when the raw input cannot be typed against the operation's schema.
 *
 * Carries EVERY problem rather than the first: a caller fixing a command line wants the whole
 * list in one pass, not one error per attempt.
 */
final class SchemaCoercionException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('input validation failed: ' . implode('; ', $errors));
    }
}
