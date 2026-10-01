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
 * A signer that can say why it returned nothing.
 *
 * Separate from {@see OperationSigner} on purpose, as tool-runtime's `ExplainsRefusal` is separate from
 * its verifier: the port keeps answering `null` on every failure, so a host's own signer keeps working
 * unchanged, and only a signer that can tell the cases apart is asked.
 */
interface ExplainsSigningFailure
{
    /**
     * Why the last refused signature was not produced, or null when this signer cannot tell.
     *
     * Asked only after {@see OperationSigner::sign()} returned null. The answer is text for a person;
     * nothing reads it as permission.
     */
    public function whyNotSigned(): ?SigningFailure;
}
