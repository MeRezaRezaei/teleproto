<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use PHPUnit\Framework\TestCase;

class ClientLiveModeTest extends TestCase
{
    public function testOfflineStubUnchangedByDefault(): void
    {
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $client = new Client(apiId: 1, apiHash: 'h', session: $session);
        $res = $client->call('help.getNearestDc');
        $this->assertSame('rpc_result', $res['_']);
        $this->assertSame('help.getNearestDc', $res['method']);
    }

    public function testLiveRequiresAuthKeyOrFailsFast(): void
    {
        $session = new SessionData(dcId: 2, authKey: ''); // empty key forces handshake attempt
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session))->live();

        // Connecting to a dead port must fail fast with our RuntimeException,
        // proving the live path really attempts the network (not the stub).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('127.0.0.1:1');
        $client->callToHost('127.0.0.1', 1); // port 1: connection refused
    }

    public function testFailedCallToHostDoesNotCacheHalfState(): void
    {
        $session = new SessionData(dcId: 2, authKey: '');
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session))->live();

        // First attempt: dead port -> RuntimeException with host:port context.
        try {
            $client->callToHost('127.0.0.1', 1);
            $this->fail('callToHost against a dead port must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('127.0.0.1:1', $e->getMessage());
        }

        // Second attempt must start fresh: it fails the same way (a cached
        // half-state would either short-circuit or produce a different error).
        try {
            $client->callToHost('127.0.0.1', 1);
            $this->fail('second callToHost must also throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('127.0.0.1:1', $e->getMessage());
        }

        $this->assertNull(self::connOf($client), 'no connection may be cached after failure');
        $this->assertSame('', $session->authKey, 'failed handshake must not store a partial key');
    }

    private static function connOf(Client $client): mixed
    {
        $prop = new \ReflectionProperty(Client::class, 'conn');
        return $prop->getValue($client);
    }
}
