<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\Console\Tests;

use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use Milpa\Console\SchemaCoercer;
use Milpa\Console\SchemaCoercionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StructuredInputTest extends TestCase
{
    private const SCHEMA = ['properties' => [
        'source' => ['type' => 'object'],
        'edits' => ['type' => 'array', 'items' => ['type' => 'object']],
        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        'text' => ['type' => 'string'],
    ]];

    public function testCliAndHttpDeliverTheSameStructuredInputWithoutReinterpretingContent(): void
    {
        $object = ['session' => 's-1', 'seq' => 7, 'sha256' => str_repeat('a', 64),
            'nested' => ['title' => '001', 'enabled' => false, 'nothing' => null, 'data' => [1, 2]]];
        $pairs = [['find' => "\$x = 'false';\n", 'replace' => "\$x = 'λ';\n"], ['find' => 'A=B', 'replace' => '{}']];
        $expected = ['source' => $object, 'edits' => $pairs, 'tags' => ['{"keep":"string"}', 'false'], 'text' => '{"also":"string"}'];
        $argv = ['--source=' . json_encode($object, JSON_THROW_ON_ERROR)];
        foreach ($pairs as $pair) {
            $argv[] = '--edits=' . json_encode($pair, JSON_THROW_ON_ERROR);
        }
        foreach ($expected['tags'] as $tag) {
            $argv[] = '--tags=' . $tag;
        }
        $argv[] = '--text=' . $expected['text'];
        $operation = new Operation('repair', 'Transport structured input', static fn () => null, inputSchema: self::SCHEMA);

        self::assertSame($expected, (new CliRunner())->deriveInput($operation, $argv));
        self::assertSame($expected, (new SchemaCoercer())->coerce(self::SCHEMA, $expected));
    }

    #[DataProvider('invalidObjects')]
    public function testInvalidObjectsAreRefusedBeforeTheHandler(mixed $raw): void
    {
        $this->expectException(SchemaCoercionException::class);
        (new SchemaCoercer())->coerce(self::SCHEMA, ['source' => $raw]);
    }

    public static function invalidObjects(): iterable
    {
        yield 'invalid JSON' => ['{'];
        yield 'JSON string' => ['"string"'];
        yield 'JSON number' => ['7'];
        yield 'JSON null' => ['null'];
        yield 'JSON list' => ['[]'];
        yield 'native list' => [['first', 'second']];
        yield 'native null' => [null];
        yield 'native boolean' => [false];
        yield 'too deep' => [str_repeat('{"a":', 65) . '0' . str_repeat('}', 65)];
    }

    public function testMalformedRepeatedObjectNamesItsFieldAndIndex(): void
    {
        try {
            (new SchemaCoercer())->coerce(self::SCHEMA, ['edits' => ['{"find":"x"}', '{invalid']]);
            self::fail('Expected malformed repeated object to be refused');
        } catch (SchemaCoercionException $error) {
            self::assertSame(["field 'edits': item '1': expected a valid JSON object"], $error->errors);
        }
    }

    public function testEmptyObjectAndOptionalObjectRemainDistinct(): void
    {
        $coercer = new SchemaCoercer();
        self::assertSame([], $coercer->coerce(self::SCHEMA, []));
        self::assertSame(['source' => []], $coercer->coerce(self::SCHEMA, ['source' => '{}']));
        self::assertSame(['source' => []], $coercer->coerce(self::SCHEMA, ['source' => []]));
    }

    public function testUntypedArraysRetainTheirExistingBytes(): void
    {
        $schema = ['properties' => ['values' => ['type' => 'array']]];
        $input = ['values' => ['{"first":1}', 'false', '001']];
        self::assertSame($input, (new SchemaCoercer())->coerce($schema, $input));
    }
}
