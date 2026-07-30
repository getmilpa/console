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
 * Una operación vista desde MCP: la herramienta que un agente lista y llama.
 *
 * Lleva una REFERENCIA al handler, no el callable resuelto — clase y método, tal como la operación lo
 * declaró. Resolverlo contra el contenedor le toca al materializador, y esa es la diferencia entre un
 * modelo que se puede serializar, cachear y comparar, y uno que arrastra un closure y por lo tanto no
 * sale del proceso. Un handler que ya era `callable` viaja tal cual y `toArray()` lo reporta como
 * `closure`, porque de un closure no hay nada honesto que escribir.
 */
final readonly class McpToolModel implements SurfaceModel
{
    /**
     * @param array<string, mixed>                       $inputSchema
     * @param array<string, mixed>|null                  $outputSchema
     * @param list<string>                               $scopes
     * @param callable|array{0: class-string, 1: string} $handler      referencia sin resolver
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public mixed $handler,
        public array $scopes = [],
        public bool $mutating = false,
        public bool $requiresConfirmation = false,
        public ?string $version = null,
        public ?array $outputSchema = null,
    ) {
    }

    /** La superficie de este modelo — `mcp`. */
    public function surface(): string
    {
        return 'mcp';
    }

    /**
     * El modelo como datos planos. El handler sale como `Clase::metodo` cuando es una
     * referencia, y como `closure` cuando no lo es — de un closure no hay nada honesto que
     * escribir.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'surface' => 'mcp',
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->outputSchema,
            'handler' => \is_array($this->handler) ? implode('::', $this->handler) : 'closure',
            'scopes' => $this->scopes,
            'mutating' => $this->mutating,
            'requiresConfirmation' => $this->requiresConfirmation,
            'version' => $this->version,
        ];
    }
}
