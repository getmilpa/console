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

use Milpa\Console\FileConfirmTokenStore;
use PHPUnit\Framework\TestCase;

/**
 * The whole point of the file-backed store: a token survives the process that issued it, so the HTTP
 * two-step confirm gate can complete across the separate processes a real deployment spawns per request
 * (greenhouse evidence/0359). Every case uses a FRESH store instance over the same path — the in-memory
 * store fails exactly here.
 */
final class FileConfirmTokenStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-confirm-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testATokenSurvivesAcrossFreshInstances(): void
    {
        $token = (new FileConfirmTokenStore($this->path))->issue('lab:touch');

        // A DIFFERENT instance over the same path — what every HTTP request is.
        self::assertTrue((new FileConfirmTokenStore($this->path))->consume($token, 'lab:touch'));
    }

    public function testOneTimeUseAcrossInstances(): void
    {
        $token = (new FileConfirmTokenStore($this->path))->issue('lab:touch');
        self::assertTrue((new FileConfirmTokenStore($this->path))->consume($token, 'lab:touch'));
        self::assertFalse((new FileConfirmTokenStore($this->path))->consume($token, 'lab:touch'), 'a spent token is dead');
    }

    public function testWrongOperationIsRejectedAndBurnsTheToken(): void
    {
        $token = (new FileConfirmTokenStore($this->path))->issue('lab:touch');
        self::assertFalse((new FileConfirmTokenStore($this->path))->consume($token, 'other:op'));
        // one-time use even on mismatch: a wrong guess must not leave it live
        self::assertFalse((new FileConfirmTokenStore($this->path))->consume($token, 'lab:touch'));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = (new FileConfirmTokenStore($this->path, ttlSeconds: -1))->issue('lab:touch');
        self::assertFalse((new FileConfirmTokenStore($this->path))->consume($token, 'lab:touch'));
    }

    public function testAnUnknownTokenIsRejected(): void
    {
        self::assertFalse((new FileConfirmTokenStore($this->path))->consume('deadbeef', 'lab:touch'));
    }
}
