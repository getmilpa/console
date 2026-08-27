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
 * A persistent one-time confirm-token store, backing the HTTP confirm gate across the separate processes
 * a real deployment spawns per request. Same one-time-use + TTL semantics as {@see ConfirmTokenStore},
 * but the token map lives in a JSON file mutated under an exclusive lock, so a token issued while handling
 * the 428 survives to be consumed by the client's retry — the gap greenhouse evidence/0359 measured, where
 * the in-memory default made the two-step gate impossible to complete over stateless HTTP.
 */
final class FileConfirmTokenStore implements ConfirmTokens
{
    public function __construct(private readonly string $path, private readonly int $ttlSeconds = 60)
    {
    }

    /** Mints a one-time token bound to `$operation`, persisted for the store's TTL. */
    public function issue(string $operation): string
    {
        $token = bin2hex(random_bytes(16));
        $this->mutate(function (array $tokens) use ($token, $operation): array {
            $tokens[$token] = ['operation' => $operation, 'expires' => time() + $this->ttlSeconds];

            return $tokens;
        });

        return $token;
    }

    /** Spends the token from the file, answering whether it was valid for `$operation` and still fresh. */
    public function consume(string $token, string $operation): bool
    {
        $valid = false;
        $this->mutate(function (array $tokens) use ($token, $operation, &$valid): array {
            $entry = $tokens[$token] ?? null;
            unset($tokens[$token]); // one-time use, even on mismatch
            if ($entry !== null && $entry['operation'] === $operation && $entry['expires'] >= time()) {
                $valid = true;
            }

            return $tokens;
        });

        return $valid;
    }

    /**
     * Read-modify-write the token map under an exclusive lock, pruning expired entries first so the file
     * cannot grow without bound. A store it cannot open (unwritable path) refuses silently — the gate then
     * fails closed, which is the safe direction.
     *
     * @param callable(array<string, array{operation: string, expires: int}>): array<string, array{operation: string, expires: int}> $fn
     */
    private function mutate(callable $fn): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $fh = @fopen($this->path, 'c+');
        if ($fh === false) {
            return;
        }

        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $now = time();

            // The file is untrusted input: narrow it to the known shape and prune expired entries, so
            // the callback (and consume()) work against a validated map, never raw JSON.
            /** @var array<string, array{operation: string, expires: int}> $tokens */
            $tokens = [];
            foreach (is_array($decoded) ? $decoded : [] as $key => $entry) {
                if (
                    is_string($key)
                    && is_array($entry)
                    && isset($entry['operation'], $entry['expires'])
                    && is_string($entry['operation'])
                    && (int) $entry['expires'] >= $now
                ) {
                    $tokens[$key] = ['operation' => $entry['operation'], 'expires' => (int) $entry['expires']];
                }
            }

            $tokens = $fn($tokens);

            $out = json_encode($tokens);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $out === false ? '{}' : $out);
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
