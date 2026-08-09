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
use Milpa\Command\SurfaceProjector;
use Milpa\Console\Model\CliCommandModel;

/**
 * Proyecta una operación al comando que una terminal expone.
 *
 * Es PURO: no ejecuta, no escribe, no consulta al contenedor. Ejecutar la operación y pintar su
 * resultado vive en CliRunner, y esa separación es la cláusula 1 de ADR-0035 — un projector nunca
 * produce representación física.
 *
 * Antes esta clase corría el comando y escribía en la terminal por un callable `$out`. Que un
 * projector emitiera texto era justo lo que impedía cambiar el renderer de la superficie sin
 * tocarlo, y lo que obligaba a probarlo con un colaborador para ver lo que había proyectado.
 */
final class CliProjector implements SurfaceProjector
{
    /** The surface tag this projector answers for — `cli`. */
    public function surface(): string
    {
        return 'cli';
    }

    /** Whether the operation opted into this surface; `surfaces: null` means every one. */
    public function supports(Operation $op): bool
    {
        return $op->supportsSurface('cli');
    }

    /**
     * Proyecta la operación al comando que una terminal expone: nombre, descripción y las banderas
     * que su esquema declara, con su tipo y si son obligatorias.
     *
     * Se deriva del `inputSchema` y NO de argv: argv pertenece a una invocación concreta, mientras
     * que esto describe la superficie, que existe antes de que nadie escriba nada. Devuelve un valor
     * y no ejecuta — {@see self::run()} sigue siendo quien materializa, y separarlos es lo que
     * permite pedir este modelo para una ayuda o un autocompletado sin correr la operación.
     */
    public function project(Operation $op): CliCommandModel
    {
        /** @var array<string, array<string, mixed>> $propiedades */
        $propiedades = \is_array($op->inputSchema['properties'] ?? null) ? $op->inputSchema['properties'] : [];
        /** @var list<string> $obligatorias */
        $obligatorias = \is_array($op->inputSchema['required'] ?? null) ? array_values($op->inputSchema['required']) : [];

        $flags = [];
        foreach ($propiedades as $nombre => $definicion) {
            $flags[$nombre] = [
                'type' => \is_string($definicion['type'] ?? null) ? $definicion['type'] : 'string',
                'required' => \in_array($nombre, $obligatorias, true),
                'description' => \is_string($definicion['description'] ?? null) ? $definicion['description'] : '',
            ];
        }

        return new CliCommandModel(
            name: $op->name,
            description: $op->description,
            flags: $flags,
            needsSignature: Consent::demanded($op),
        );
    }
}
