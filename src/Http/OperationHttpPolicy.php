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

namespace Milpa\Console\Http;

use Milpa\Command\OperationHttpPolicy as ContratoDeCommand;

/**
 * El contrato de política HTTP, que ahora vive en `milpa/command`.
 *
 * Nació aquí, con el proyector, y eso obligaba a quien la implementa —`milpa/auth`, que es piso— a
 * depender de `milpa/console`, que arrastra plugins, live y tool-runtime. La dependencia iba al
 * revés, así que el contrato se mudó a `milpa/command`, donde vive `Operation` y donde no cuesta
 * nada.
 *
 * Esta interfaz SE QUEDA extendiendo a la otra en vez de desaparecer: quien la haya implementado
 * —era pública desde 0.4.0— sigue funcionando sin tocar una línea, y quien la reciba por parámetro
 * también, porque un `Milpa\Command\OperationHttpPolicy` la satisface. Preferir la de `milpa/command`
 * en código nuevo.
 *
 * @deprecated 0.5.0 usa {@see \Milpa\Command\OperationHttpPolicy}
 */
interface OperationHttpPolicy extends ContratoDeCommand
{
}
