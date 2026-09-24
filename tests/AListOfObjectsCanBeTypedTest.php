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

namespace Milpa\Console\Tests;

use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A property that asks for a list of OBJECTS can be typed on a terminal.
 *
 * The convention is one flag per element — `--tag=a --tag=b` — and that carries only scalars. So
 * `screen:declare --columns='[{"key":"title","label":"Title"}]'` arrived as a list of ONE STRING and
 * the screen rendered with no columns (greenhouse evidence/0995): an operation whose schema asks for
 * objects was inexpressible from the one surface a human types into.
 *
 * @guards a JSON list flag taken as its elements, and every other value unchanged
 *
 * @fires  on every CLI invocation of an operation with an array input
 *
 * @refuses nothing — a value that is not a JSON list is one element, exactly as before
 *
 * @subject-in milpa/console
 */
#[CoversClass(CliRunner::class)]
final class AListOfObjectsCanBeTypedTest extends TestCase
{
    public function testAJsonListFlagIsTakenAsItsElements(): void
    {
        $input = $this->derive(['--columns=[{"key":"title","label":"Title"},{"key":"body","label":"Body"}]']);

        self::assertSame(
            [['key' => 'title', 'label' => 'Title'], ['key' => 'body', 'label' => 'Body']],
            $input['columns'],
            'two objects, not one string',
        );
    }

    public function testRepeatedScalarFlagsBehaveExactlyAsBefore(): void
    {
        // THE CONTROL: the convention every existing caller relies on is untouched.
        self::assertSame(['a', 'b'], $this->derive(['--columns=a', '--columns=b'])['columns']);
        self::assertSame(['only'], $this->derive(['--columns=only'])['columns'], 'one flag is still a list of one');
    }

    public function testWhatIsNotAJsonListStaysOneElement(): void
    {
        self::assertSame(['[not json'], $this->derive(['--columns=[not json'])['columns'], 'malformed stays a string');
        self::assertSame(['{"a":1}'], $this->derive(['--columns={"a":1}'])['columns'], 'an object is not a list');
    }

    public function testTwoJsonListFlagsMerge(): void
    {
        self::assertSame(
            [['k' => 1], ['k' => 2]],
            $this->derive(['--columns=[{"k":1}]', '--columns=[{"k":2}]'])['columns'],
        );
    }

    /**
     * @param list<string> $argv
     *
     * @return array<string, mixed>
     */
    private function derive(array $argv): array
    {
        return (new CliRunner())->deriveInput(new Operation(
            name: 'probe',
            effects: EffectProfile::readOnly(),
            description: 'probe',
            handler: static fn (array $input): array => $input,
            inputSchema: ['type' => 'object', 'properties' => ['columns' => ['type' => 'array']], 'required' => []],
        ), $argv);
    }
}
