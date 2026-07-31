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
 * Alguien decidió que esta operación no corriera.
 *
 * Es una excepción y no un resultado porque no HAY resultado: nadie contestó por ella. Un `null`
 * devuelto en su lugar sería indistinguible de una operación que corrió y no devolvió nada, y esa
 * confusión aparece en la superficie, donde ya nadie puede saber cuál de las dos pasó.
 */
final class OperationStoppedException extends \RuntimeException
{
}
