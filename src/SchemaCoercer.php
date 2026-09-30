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
 * Coerces a raw string-keyed input bag into typed values per a JSON-Schema-shaped inputSchema,
 * applying defaults and validating required/enum. A minimal, dependency-free stand-in for
 * tool-runtime's SchemaValidator (which is suggest-only in the skeleton) so the CLI and HTTP
 * projectors can type inputs without pulling the agent surface.
 */
final class SchemaCoercer
{
    /**
     * Types the raw string bag a shell hands over, against the schema the operation declares.
     *
     * Everything arrives from argv as a string, so `--count=3` is `"3"` until something says the
     * schema wanted an integer. Coercing before the consent gate is deliberate: the signature
     * covers the typed arguments, which are the ones the handler receives.
     *
     * @param array<string, mixed>                           $inputSchema JSON-Schema-shaped
     * @param array<string, string|array<int|string, mixed>> $raw         raw string/array inputs
     *
     * @return array<string, mixed> typed input
     *
     * @throws SchemaCoercionException
     */
    public function coerce(array $inputSchema, array $raw): array
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = \is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
        /** @var list<string> $required */
        $required = \is_array($inputSchema['required'] ?? null) ? array_values($inputSchema['required']) : [];

        $out = [];
        $errors = [];

        if (($inputSchema['additionalProperties'] ?? null) === false) {
            foreach ($raw as $name => $_value) {
                if (!\array_key_exists($name, $properties)) {
                    $errors[] = "unknown field '{$name}'";
                }
            }
        }

        foreach ($properties as $name => $spec) {
            $declared = $spec['type'] ?? null;
            $type = \is_string($declared) ? $declared : 'string';

            if (!\array_key_exists($name, $raw)) {
                if (\array_key_exists('default', $spec)) {
                    $out[$name] = $spec['default'];
                } elseif (\in_array($name, $required, true)) {
                    $errors[] = "missing required field '{$name}'";
                }
                continue;
            }

            try {
                $value = \is_array($declared) && $declared !== []
                    ? $this->coerceToOneOf($raw[$name], array_values(array_filter($declared, \is_string(...))), $spec)
                    : $this->coerceValue($raw[$name], $type, $spec);
            } catch (\InvalidArgumentException $e) {
                $errors[] = "field '{$name}': " . $e->getMessage();
                continue;
            }

            if (isset($spec['enum']) && \is_array($spec['enum']) && !\in_array($value, $spec['enum'], true)) {
                $errors[] = "field '{$name}': value not allowed";
                continue;
            }

            $out[$name] = $value;
        }

        if ($errors !== []) {
            throw new SchemaCoercionException($errors);
        }

        return $out;
    }

    /**
     * A declared LIST of JSON types (`"type": ["string", "object", …]`) admits a value of any of them.
     *
     * HTTP has already decoded its body, so a `true`, a `3` or an object arrives as itself and is kept
     * when its JSON type is on the list. Read with the single-type fallback, the list became `string`:
     * a JSON `true` reached the operation as `"1"` and an object was refused outright (greenhouse
     * evidence/1059). Text stays text when the list admits strings, because argv has no other spelling
     * and the operation that declared the list is the one that reads it. Anything else is tried
     * against the listed types in the order they are declared.
     *
     * @param list<string>         $types
     * @param array<string, mixed> $spec
     */
    private function coerceToOneOf(mixed $raw, array $types, array $spec): mixed
    {
        if (\is_string($raw) ? \in_array('string', $types, true) : array_intersect($this->jsonTypesOf($raw), $types) !== []) {
            return $raw;
        }

        foreach (array_intersect($types, ['integer', 'number', 'boolean', 'object', 'array', 'string']) as $type) {
            try {
                return $this->coerceValue($raw, $type, $spec);
            } catch (\InvalidArgumentException) {
            }
        }

        throw new \InvalidArgumentException('expected one of: ' . implode(', ', $types));
    }

    /**
     * The JSON types a decoded value can be read as — an empty PHP array is both `[]` and `{}`.
     *
     * @return list<string>
     */
    private function jsonTypesOf(mixed $value): array
    {
        return match (true) {
            $value === null => ['null'],
            \is_bool($value) => ['boolean'],
            \is_int($value) => ['integer', 'number'],
            \is_float($value) => ['number'],
            $value === [] => ['array', 'object'],
            \is_array($value) => array_is_list($value) ? ['array'] : ['object'],
            default => [],
        };
    }

    /** @param array<string, mixed> $spec */
    private function coerceValue(mixed $raw, string $type, array $spec): mixed
    {
        return match ($type) {
            'integer' => $this->toInt($raw),
            'number' => $this->toFloat($raw),
            'boolean' => $this->toBool($raw),
            'object' => $this->toObject($raw),
            'array' => $this->toArray($raw, $spec),
            default => \is_scalar($raw) ? (string) $raw : throw new \InvalidArgumentException('expected a string'),
        };
    }

    /**
     * Transport an object without changing its property values or interpreting its strings.
     *
     * The CLI supplies a JSON object; HTTP already decoded its body into associative arrays.
     * Nested validation remains the declared handler's responsibility, as for other compound input.
     *
     * @return array<int|string, mixed>
     */
    private function toObject(mixed $raw): array
    {
        if (\is_string($raw)) {
            try {
                // Inspect the JSON shape before associative decoding loses {} versus [].
                if (!json_decode($raw, false, 64, JSON_THROW_ON_ERROR) instanceof \stdClass) {
                    throw new \InvalidArgumentException('expected a JSON object');
                }
                return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \InvalidArgumentException('expected a valid JSON object');
            }
        }
        // HTTP's existing associative decode represents an empty object as [].
        if (\is_array($raw) && ($raw === [] || !array_is_list($raw))) {
            return $raw;
        }
        throw new \InvalidArgumentException('expected an object');
    }

    /**
     * Decode repeated CLI object flags only when the item schema explicitly declares objects.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<int|string, mixed>
     */
    private function toArray(mixed $raw, array $spec): array
    {
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('expected an array');
        }
        if (!\is_array($spec['items'] ?? null) || ($spec['items']['type'] ?? null) !== 'object') {
            return $raw;
        }
        $out = [];
        foreach ($raw as $key => $item) {
            try {
                $out[$key] = $this->toObject($item);
            } catch (\InvalidArgumentException $error) {
                throw new \InvalidArgumentException("item '{$key}': " . $error->getMessage());
            }
        }
        return $out;
    }

    private function toInt(mixed $raw): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }
        throw new \InvalidArgumentException('expected an integer');
    }

    private function toFloat(mixed $raw): float
    {
        if (\is_int($raw) || \is_float($raw)) {
            return (float) $raw;
        }
        if (\is_string($raw) && is_numeric($raw)) {
            return (float) $raw;
        }
        throw new \InvalidArgumentException('expected a number');
    }

    private function toBool(mixed $raw): bool
    {
        if (\is_bool($raw)) {
            return $raw;
        }
        if (\in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (\in_array($raw, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
        throw new \InvalidArgumentException('expected a boolean');
    }
}
