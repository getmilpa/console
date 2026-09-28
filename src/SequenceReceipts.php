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

use Milpa\ToolRuntime\Identity\GrantedAuthorization;

/**
 * Where a host keeps the signature that opened a sequence, so the calls after it can cite it
 * (greenhouse decisions/0458, 0500).
 *
 * ── WHY THE DOOR NEEDS A BOOK IT DOES NOT OWN ──────────────────────────────────────────────────
 *
 * The replay ledger forgets on purpose: it only knows `spend()`, and remembering an authorization
 * past its freshness window would be «keeping proof of something already impossible». So the
 * terminal has no record of what it authorized, and a resumed call cannot ask it. The receipt has
 * to be kept by whoever holds the SEQUENCE — an agent session, a paused recipe — and this is the
 * seam through which the door hands it over and reads it back.
 *
 * ── WHAT THIS BOOK MAY NOT DO ───────────────────────────────────────────────────────────────────
 *
 * Judge. It stores and returns bytes. Re-verifying the signature, binding it to the sequence and
 * asking who the signer is today stay at the door ({@see CliRunner}), because a book that answered
 * «still valid» would be a stored grade — the coin greenhouse evidence/0254 measured being forged.
 * What it does decide is LIFE: a receipt of a sequence that ended is not returned.
 */
interface SequenceReceipts
{
    /**
     * Keep the verified signature a call just ran under as its sequence's receipt — unless the call
     * already ended the sequence.
     *
     * Called after the call ran, because the sequence may only exist once its handler created it,
     * and it receives the call's result because only the host can read from it whether the sequence
     * is over (a recipe applied, a task closed). A host whose sequence does not exist keeps nothing,
     * and a sequence that ended keeps nothing standing — the next call signs again.
     *
     * @param mixed $result what the handler returned, or null when it threw
     */
    public function record(string $sequence, string $operation, GrantedAuthorization $granted, mixed $result): void;

    /**
     * The receipt standing for this sequence, exactly as kept, or null when none stands.
     *
     * @return array{operation: string, payload: string, signature: string, fingerprint: string, uid?: ?string}|null
     */
    public function standing(string $sequence): ?array;

    /**
     * Record that a call ran citing the standing receipt instead of a new signature.
     *
     * @param string $receiptId `sha256:<digest of the signed payload>`, the id the opening call ran under
     */
    public function cited(string $sequence, string $operation, string $receiptId): void;

    /**
     * A call that cited the receipt finished: if its result says the sequence ended, the receipt
     * stops standing, so it cannot become a standing key for whatever comes next.
     *
     * @param mixed $result what the handler returned, or null when it threw
     */
    public function settled(string $sequence, string $operation, mixed $result): void;
}
