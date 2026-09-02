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

use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Effect\VerifiedPrincipal;
use Milpa\Command\Operation;
use Milpa\Console\Consent;
use PHPUnit\Framework\TestCase;

/**
 * D-01 (greenhouse decisions/0187): una demanda, satisfecha por cualquier prueba suficiente.
 *
 * `demanded()` es surface-agnóstico. Satisfacerla se había vuelto surface-específico: el CLI con una
 * firma gpg, la sesión con un grant de `perm:`. `satisfiedBy()` cierra el hueco — una demanda está
 * satisfecha cuando algún grant en mano ADMITE la llamada exacta (la cubre y trae prueba viva). El
 * caso que importa es la falla-cerrada: un sí sin prueba, o una intención de MODELO no verificada, no
 * compra jamás una demanda de firma.
 */
final class ConsentSatisfactionTest extends TestCase
{
    private const AT = '2026-09-02 10:00:00';

    /** Una lectura no demanda nada: satisfecha sin ningún grant. */
    public function testANonDemandedCallIsSatisfiedWithNoGrant(): void
    {
        self::assertTrue(Consent::satisfiedBy($this->readOp(), [], [], 'ses-A'));
    }

    /**
     * EXACTITUD DE VECTOR (Finding 1 de la revisión adversarial): un sí a `{name:a2a}` NO satisface
     * `{name:a2a, force:true}`. La proyección humana debe ser INTERCAMBIABLE con la firma gpg, que
     * liga el vector completo; una llave más ancha rompería «una autoridad, muchas proyecciones».
     */
    public function testAnExtraCallArgumentTheHumanNeverSawIsNotSatisfied(): void
    {
        $grant = $this->intentGrant(['name' => 'a2a'], 'ses-A');

        self::assertTrue(Consent::satisfiedBy($this->demandingOp(), ['name' => 'a2a'], [$grant], 'ses-A'));
        self::assertFalse(
            Consent::satisfiedBy($this->demandingOp(), ['name' => 'a2a', 'force' => true], [$grant], 'ses-A'),
            'un argumento de más que el humano nunca confirmó no se satisface',
        );
    }

    /**
     * SESIÓN EXACTA (Finding 3): una consulta SIN sesión no limpia un grant proof-backed, aunque el
     * grant cubra la operación y los argumentos. Una autoridad de privilegio no cae abierta por un
     * null olvidado.
     */
    public function testANullQuerySessionDoesNotSatisfyAProofBackedGrant(): void
    {
        $grant = $this->intentGrant(['name' => 'a2a'], 'ses-A');

        self::assertFalse(Consent::satisfiedBy($this->demandingOp(), ['name' => 'a2a'], [$grant], null));
    }

    /** LA FALLA-CERRADA: una demanda no se satisface con un grant sin prueba. */
    public function testADemandedCallIsNotSatisfiedByAnUngradedGrant(): void
    {
        $sinPrueba = new ConsentGrant(
            operation: new OperationId('capabilities.enable'),
            principal: 'cli:rod@cm4070',
            session: 'ses-A',
            grantedAt: new \DateTimeImmutable(self::AT),
            provenance: 'session.question_answered',
            arguments: ['name' => 'a2a'],
        );

        self::assertTrue(Consent::demanded($this->demandingOp(), ['name' => 'a2a']));
        self::assertFalse(
            Consent::satisfiedBy($this->demandingOp(), ['name' => 'a2a'], [$sinPrueba], 'ses-A'),
            'un sí grabado sin prueba viva no limpia una demanda de firma',
        );
    }

    /** UNA INTENCIÓN DE MODELO no verificada tampoco: cubre por argumentos, pero no trae prueba. */
    public function testAModelIntentClaimDoesNotSatisfyADemand(): void
    {
        $claim = new ConsentGrant(
            operation: new OperationId('capabilities.enable'),
            principal: null,
            session: 'ses-A',
            grantedAt: new \DateTimeImmutable(self::AT),
            provenance: 'intent-confirmed',
            arguments: ['name' => 'a2a'],
        );

        self::assertFalse(
            Consent::satisfiedBy($this->demandingOp(), ['name' => 'a2a'], [$claim], 'ses-A'),
            'la intención del modelo describe qué quiere, no compra la autoridad de un privilegio',
        );
    }

    /** La proyección proof-backed SÍ satisface — una autoridad, muchas proyecciones. */
    public function testADemandedCallIsSatisfiedByAProofBackedIntentGrant(): void
    {
        $grant = $this->intentGrant(['name' => 'a2a'], 'ses-A');

        self::assertTrue(Consent::satisfiedBy($this->demandingOp(), ['name' => 'a2a'], [$grant], 'ses-A'));
    }

    /** Un sí verificado para OTRA llamada no satisface esta: exactitud preservada. */
    public function testAProofBackedGrantForAnotherCallDoesNotSatisfy(): void
    {
        $otraOperacion = ConsentGrant::fromVerifiedIntent(
            new OperationId('plugins.register'),
            VerifiedPrincipal::admit('desktop:rod', 'desktop', ['plugins.register'], 'passkey', 'a-real-authenticator'),
            'ses-A',
            new \DateTimeImmutable(self::AT),
            ['name' => 'a2a'],
        );
        $otrosArgumentos = $this->intentGrant(['name' => 'exfil'], 'ses-A');
        $otraSesion = $this->intentGrant(['name' => 'a2a'], 'ses-B');

        foreach ([$otraOperacion, $otrosArgumentos, $otraSesion] as $grant) {
            self::assertFalse(
                Consent::satisfiedBy($this->demandingOp(), ['name' => 'a2a'], [$grant], 'ses-A'),
            );
        }
    }

    /** Entre varios grants en mano, basta uno que admita. */
    public function testItSufficesForOneGrantAmongManyToAdmit(): void
    {
        $ruido = new ConsentGrant(
            operation: new OperationId('config.set'),
            principal: 'cli:rod@cm4070',
            session: 'ses-A',
            grantedAt: new \DateTimeImmutable(self::AT),
            provenance: 'session.question_answered',
        );

        self::assertTrue(
            Consent::satisfiedBy($this->demandingOp(), ['name' => 'a2a'], [$ruido, $this->intentGrant(['name' => 'a2a'], 'ses-A')], 'ses-A'),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function intentGrant(array $arguments, string $sesion): ConsentGrant
    {
        return ConsentGrant::fromVerifiedIntent(
            new OperationId('capabilities.enable'),
            VerifiedPrincipal::admit('desktop:rod', 'desktop', ['capabilities.enable'], 'passkey', 'a-real-authenticator'),
            $sesion,
            new \DateTimeImmutable(self::AT),
            $arguments,
        );
    }

    /** Executable bajo Privileged: demanda consentimiento por la regla S2, sin bandera declarada. */
    private function demandingOp(): Operation
    {
        return new Operation(
            name: 'capabilities.enable',
            description: 'enables an executable capability under a privileged authority',
            handler: static fn (): array => [],
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Privileged,
                subject: Subject::Executable,
                rollbackContract: 'synthetic probe',
            ),
        );
    }

    private function readOp(): Operation
    {
        return new Operation(
            name: 'status.show',
            description: 'reads and shows status',
            handler: static fn (): array => [],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'nothing is written',
            ),
        );
    }
}
