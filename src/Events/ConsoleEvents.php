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

namespace Milpa\Console\Events;

use Milpa\Console\OperationRunner;
use Milpa\Interfaces\Event\EventDeclaration;

/**
 * Every event this package dispatches, declared next to the code that dispatches it.
 *
 * The constants ARE the names {@see OperationRunner} hands to `dispatch()`, and the declarations are
 * built from the same constants: a renamed event cannot leave a stale declaration behind, and a
 * declaration nobody dispatches — or a dispatch nobody declares — is what the falsifier
 * (`TheEmitterDeclaresEveryEventItDispatchesTest`) exists to catch.
 *
 * The emitter is the authority on what events exist, and the dispatcher is the one place every
 * dispatch passes through; so the emitter declares to the dispatcher at the site where it receives
 * it, and the house counts what was declared against what was dispatched (greenhouse
 * decisions/0228). Declaring is not enforced: a dispatcher that does not count declarations is
 * asked nothing, and `dispatch()` of an undeclared name keeps working.
 */
final class ConsoleEvents
{
    /**
     * Before an operation runs. The payload carries an `InterceptionSlot` under `slot`, so a listener
     * can stop the operation or answer for it.
     */
    public const string EXECUTING = 'operation.executing';

    /**
     * After an operation reached an outcome — it ran, it was answered for, it was stopped, or it
     * threw. Emitted for every outcome, so an audit has no holes.
     */
    public const string EXECUTED = 'operation.executed';

    /**
     * One declaration per event name this package dispatches, in the order they fire.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new EventDeclaration(
                name: self::EXECUTING,
                dispatchedBy: OperationRunner::class,
                when: 'Before an operation runs, whatever surface it came in by; a listener may stop it or answer for it.',
                subjectKey: 'event',
                subjectType: OperationExecutingEvent::class,
                mutable: false,
                interceptable: true,
            ),
            new EventDeclaration(
                name: self::EXECUTED,
                dispatchedBy: OperationRunner::class,
                when: 'After an operation reached an outcome: it ran, was answered for, was stopped, or threw.',
                subjectKey: 'event',
                subjectType: OperationExecutedEvent::class,
                mutable: false,
                interceptable: false,
            ),
        ];
    }
}
