<?php

declare(strict_types=1);

namespace Milpa\Console\Tests;

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Descent;
use Milpa\Command\Effect\DescentCertificate;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\Consent;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse evidence/0152 froze before this class could read an argument.
 *
 * `command v0.8.0` shipped the descent field, `capabilities:enable` declared one with two
 * measurements behind it, and five green cases proved the field itself — and a grep for `forCall`
 * across the whole vendor tree returned one result: its own definition. The field existed and
 * nothing consulted it, because consent was decided from the OPERATION and never from the CALL.
 *
 * A FIELD IS NOT A MECHANISM. That is greenhouse MILPA-G002 one floor down from where it was
 * written, and it is the third time this shape has been found here: a rule with no wiring, a law
 * with no gate, and now a field with no reader. All three share the same tell — the artifact's own
 * unit test passes while the path that would use it does not exist.
 *
 * The second case is the control. If the same call demands the same thing with and without the
 * argument, nothing here is measuring the descent; it is measuring something that already happened.
 *
 * Case 5 is what `command v0.10.0` added, landing greenhouse decisions/0050: the reason stopped
 * being the key. A descent now lowers a ceiling only against a certificate bound to the handler that
 * is about to run, so consent is decided from `Operation::ceilingForCall()` — the only place holding
 * both the declared effects and the code they describe. Case 1 is its positive control and carries
 * `F-6` of decisions/0045: a certified, honest descent must STILL lower, or the mechanism cost
 * something and bought nothing.
 *
 * Case 6 is what `command v0.11.0` added: the payload must also say where it came from. An unsigned
 * certificate is not a certificate with an open question — `evidence/0249` deleted the artifact,
 * rewrote it with a text editor, and the ceiling came down.
 */
final class ConsentDescentTest extends TestCase
{
    private string $publica = '';

    private string $privada = '';

    protected function setUp(): void
    {
        $par = sodium_crypto_sign_keypair();
        $this->publica = base64_encode(sodium_crypto_sign_publickey($par));
        $this->privada = sodium_crypto_sign_secretkey($par);
    }

    /** 1 · with the argument, the declared descent lands and the operation runs on its own. */
    public function testTheArgumentBringsTheCeilingDown(): void
    {
        self::assertFalse(Consent::demanded($this->conDescenso(), ['dry_run' => true]));
    }

    /**
     * 2 · THE CONTROL: the same operation without the argument still demands consent.
     *
     * Without this, a `demanded()` that had simply stopped working would pass case one and read as
     * a descent that works.
     */
    public function testWithoutTheArgumentTheSameOperationStillDemandsConsent(): void
    {
        self::assertTrue(Consent::demanded($this->conDescenso(), ['dry_run' => false]));
    }

    /**
     * 3 · no arguments at all — a catalogue surface — and the ceiling stays up.
     *
     * Four of the seven call sites list, project or paint operations with no invocation in hand, so
     * there are no arguments to read. Failing upward there is rule 3 of greenhouse decisions/0029
     * read literally: a descent with nothing holding it up lowers nothing. A conservative listing
     * lies toward the safe side; an optimistic one promises not to ask and then asks.
     */
    public function testWithNoArgumentsTheCeilingStaysUp(): void
    {
        self::assertTrue(Consent::demanded($this->conDescenso()));
    }

    /**
     * 4 · a descent with no reason lowers nothing, even with the argument present.
     *
     * greenhouse decisions/0029: lowering inverts the incentive that makes `escalatesOn` safe, so a
     * declaration that carries nothing to hold it up is not a descent at all.
     */
    public function testADescentWithoutAReasonLowersNothing(): void
    {
        self::assertTrue(Consent::demanded($this->conDescenso(porque: ''), ['dry_run' => true]));
    }

    /**
     * 5 · WITHOUT A CERTIFICATE, the same declaration buys nothing — greenhouse decisions/0050.
     *
     * This is `F-1` of decisions/0045 read at the surface that pays for it. Before v0.10.0 a
     * non-empty reason was the whole mechanism, and `evidence/0238` ran a handler doing exactly what
     * its descent denied: it got the lowered ceiling AND this gate's silence.
     */
    public function testWithoutACertificateTheDeclarationBuysNothing(): void
    {
        self::assertTrue(Consent::demanded($this->conDescenso(certificado: false), ['dry_run' => true]));
    }

    /** 6 · a certificate nobody signed buys nothing here either — greenhouse decisions/0051. */
    public function testAnUnsignedCertificateBuysNothing(): void
    {
        $op = $this->conDescenso();
        $descenso = $op->effects->descents[0];
        $sinFirma = new DescentCertificate(
            verifier: $descenso->certificate->verifier,
            operation: $descenso->certificate->operation,
            predicate: $descenso->certificate->predicate,
            covers: $descenso->certificate->covers,
            to: $descenso->certificate->to,
            handlerSha256: $descenso->certificate->handlerSha256,
            verifierPublicKey: $this->publica,
        );

        $desnudo = new Operation(
            name: $op->name,
            description: $op->description,
            handler: $op->handler,
            inputSchema: $op->inputSchema,
            mutating: $op->mutating,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::ThirdParty,
                reversibility: Reversibility::Compensatable,
                authority: Authority::Privileged,
                subject: Subject::Executable,
                rollbackContract: 'synthetic probe',
                descents: [new Descent(
                    argument: 'dry_run',
                    whenValue: true,
                    to: $descenso->to,
                    because: $descenso->because,
                    certificate: $sinFirma,
                )],
            ),
        );

        self::assertTrue(Consent::demanded($desnudo, ['dry_run' => true]));
    }

    private function conDescenso(
        string $porque = 'the handler returns what it would run without running it',
        bool $certificado = true,
    ): Operation {
        // The handler lives in a variable so the probe and the real operation share ONE closure:
        // the digest follows the body, and a copy on another line would be other code.
        $handler = static fn (): array => [];
        $destino = new EffectProfile(
            mutation: Mutation::None,
            externality: Externality::None,
            reversibility: Reversibility::Guaranteed,
            authority: Authority::Read,
            subject: Subject::None,
            rollbackContract: 'nothing ran, so there is nothing to undo',
        );
        $sonda = new Operation(name: 'sonda', description: 'the same handler, to read its digest', handler: $handler);

        return new Operation(
            name: 'sonda',
            description: 'a probe that declares a descent on one argument',
            handler: $handler,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::ThirdParty,
                reversibility: Reversibility::Compensatable,
                authority: Authority::Privileged,
                subject: Subject::Executable,
                rollbackContract: 'synthetic probe',
                descents: [new Descent(
                    argument: 'dry_run',
                    whenValue: true,
                    to: $destino,
                    because: $porque,
                    // Named, and signed by the verifier this test recognises — `command v0.11.0`
                    // stopped believing a payload that cannot say where it came from
                    // (greenhouse decisions/0051, priced in evidence/0249).
                    certificate: $certificado ? (new DescentCertificate(
                        verifier: 'synthetic/2026-08-18',
                        operation: 'sonda',
                        predicate: ['dry_run' => true],
                        covers: ['mutation', 'externality', 'reversibility', 'authority', 'subject'],
                        to: $destino,
                        handlerSha256: $sonda->handlerDigest(),
                        verifierPublicKey: $this->publica,
                    ))->signedWith($this->privada) : null,
                )],
            ),
        );
    }
}
