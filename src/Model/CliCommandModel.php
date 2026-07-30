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

namespace Milpa\Console\Model;

use Milpa\Command\SurfaceModel;

/**
 * Una operación vista desde una terminal: el comando y las banderas que expone.
 *
 * Las banderas se derivan del `inputSchema`, no de argv — argv es de una invocación concreta y esto
 * describe la superficie, que existe antes de que nadie escriba nada. Por eso este modelo se puede
 * pedir para imprimir una ayuda, generar autocompletado o comparar dos versiones del comando, sin
 * ejecutar la operación.
 *
 * `needsSignature` viaja en el modelo porque cambia lo que la superficie tiene que pedir: en el canal
 * cli el consentimiento es una firma que nombra la llamada, y una ayuda honesta lo dice antes.
 */
final readonly class CliCommandModel implements SurfaceModel
{
    /** @param array<string, array{type: string, required: bool}> $flags */
    public function __construct(
        public string $name,
        public string $description,
        public array $flags,
        public bool $needsSignature = false,
    ) {
    }

    /** La superficie de este modelo — `cli`. */
    public function surface(): string
    {
        return 'cli';
    }

    /**
     * El modelo como datos planos: lo que una ayuda, un autocompletado o un diff entre
     * versiones del comando necesitan leer sin ejecutar nada.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'surface' => 'cli',
            'name' => $this->name,
            'description' => $this->description,
            'flags' => $this->flags,
            'needsSignature' => $this->needsSignature,
        ];
    }
}
