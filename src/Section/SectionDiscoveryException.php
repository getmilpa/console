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

namespace Milpa\Console\Section;

/**
 * Fail-fast del discovery (ADR#4): una sección inválida es error del PRODUCTOR, con el provider
 * culpable en el mensaje — jamás degradación silente. El Hub ejecuta una redirección con estos
 * datos; no acepta un href arbitrario aunque venga de código de un plugin.
 */
final class SectionDiscoveryException extends \LogicException
{
    public const CODE_INVALID = 'MILPA_ADMIN_SECTION_INVALID';
    public const CODE_DUPLICATE = 'MILPA_ADMIN_SECTION_DUPLICATE';
    public const CODE_EMPTY = 'MILPA_ADMIN_NO_SECTIONS';

    /** Una sección cuyo id no cumple la gramática declarada. */
    public static function invalidSection(string $providerClass, string $reason): self
    {
        return new self(
            '[' . self::CODE_INVALID . "] El provider {$providerClass} contribuyó una sección inválida: {$reason}. "
            . 'Reglas: id con gramática ^[a-z][a-z0-9.-]*$, title no vacío, href local y absoluto '
            . '(/ruta — sin esquema, sin //, sin caracteres de control).',
        );
    }

    /** Dos plugins reclamando el mismo id: la navegación no sabría a cuál llevar. */
    public static function duplicateId(string $id, string $providerClass): self
    {
        return new self(
            '[' . self::CODE_DUPLICATE . "] El id de sección '{$id}' ya estaba registrado y {$providerClass} "
            . 'lo volvió a contribuir. Cada sección del admin necesita un id único entre TODOS los providers.',
        );
    }

    /** Ningún plugin aportó secciones, así que el Hub no tiene a dónde redirigir. */
    public static function noSections(): self
    {
        return new self(
            '[' . self::CODE_EMPTY . '] Ningún plugin booteado contribuyó secciones del admin. El Hub no '
            . 'puede redirigir a nada — implementa SectionProvider en al menos un plugin.',
        );
    }
}
