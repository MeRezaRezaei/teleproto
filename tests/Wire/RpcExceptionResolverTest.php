<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\Exceptions\DcMigrationException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\ApiIdException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\AuthKeyException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\FloodWaitException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\PasswordHashInvalidException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\PhoneCodeException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\RpcErrorException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\RpcExceptionResolver;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\SessionPasswordNeededException;
use PHPUnit\Framework\TestCase;

class RpcExceptionResolverTest extends TestCase
{
    public function testParameterizedFloodWaitParsesSeconds(): void
    {
        $e = RpcExceptionResolver::resolve('FLOOD_WAIT_42', 420);
        $this->assertInstanceOf(FloodWaitException::class, $e);
        $this->assertSame(42, $e->seconds);
        $this->assertSame('FLOOD_WAIT_42', $e->rpcErrorMessage);
        $this->assertStringContainsString('42 second', $e->getMessage());
    }

    public function testMigrationErrorsCarryTargetDc(): void
    {
        foreach (['PHONE_MIGRATE_4' => 4, 'USER_MIGRATE_5' => 5, 'NETWORK_MIGRATE_1' => 1] as $msg => $dc) {
            $e = RpcExceptionResolver::resolve($msg);
            $this->assertInstanceOf(DcMigrationException::class, $e, $msg);
            $this->assertSame($dc, $e->dcId, $msg);
        }
    }

    public function testTypedAuthErrors(): void
    {
        $this->assertInstanceOf(SessionPasswordNeededException::class, RpcExceptionResolver::resolve('SESSION_PASSWORD_NEEDED', 401));
        $this->assertInstanceOf(PasswordHashInvalidException::class, RpcExceptionResolver::resolve('PASSWORD_HASH_INVALID'));
        $this->assertInstanceOf(PhoneCodeException::class, RpcExceptionResolver::resolve('PHONE_CODE_EXPIRED'));
        $this->assertInstanceOf(ApiIdException::class, RpcExceptionResolver::resolve('API_ID_INVALID'));
        $this->assertInstanceOf(AuthKeyException::class, RpcExceptionResolver::resolve('SESSION_REVOKED'));
    }

    public function testUnknownErrorsFallBackWithRawMessagePreserved(): void
    {
        $e = RpcExceptionResolver::resolve('SOMETHING_NOBODY_KNOWS', 500);
        $this->assertInstanceOf(RpcErrorException::class, $e);
        $this->assertSame('SOMETHING_NOBODY_KNOWS', $e->rpcErrorMessage);
        $this->assertSame(500, $e->rpcErrorCode);
    }

    public function testCatalogHintAttachedForKnownButUntypedErrors(): void
    {
        $e = RpcExceptionResolver::resolve('PEER_ID_INVALID', 400);
        $this->assertStringContainsString('peer', $e->getMessage());
    }

    public function testTransportCodeMinus404MapsToAuthKeyUnknown(): void
    {
        $e = RpcExceptionResolver::fromTransportCode(-404);
        $this->assertInstanceOf(AuthKeyException::class, $e);
        $this->assertStringContainsString('first encrypted request on the handshake connection', $e->getMessage());
    }

    public function testEveryTypedExceptionCarriesADocHint(): void
    {
        foreach (['SESSION_PASSWORD_NEEDED', 'PASSWORD_HASH_INVALID', 'PHONE_CODE_INVALID', 'PHONE_NUMBER_BANNED', 'API_ID_INVALID', 'AUTH_KEY_UNREGISTERED'] as $msg) {
            $e = RpcExceptionResolver::resolve($msg);
            $this->assertNotSame('', $e->getMessage(), "{$msg} must carry an explanatory hint");
            $this->assertStringContainsString(':', $e->getMessage(), "{$msg} hint explains what to do");
        }
    }
}
