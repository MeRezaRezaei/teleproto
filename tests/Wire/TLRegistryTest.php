<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use PHPUnit\Framework\TestCase;

class TLRegistryTest extends TestCase
{
    public function testGoldenConstructorIds(): void
    {
        // If one of these fails, the canonical string in the registry has a typo;
        // the published ID is authoritative — fix the string, not this test.
        //
        // Goldens cross-checked against core.telegram.org/mtproto/auth_key,
        // core.telegram.org/mtproto/samples-auth_key (wire bytes) and
        // core.telegram.org/mtproto/service_messages. Three brief vectors were
        // wrong and are corrected here (see task-3-4-report.md):
        //   req_pq_multi        0x778e4dd7 -> 0xbe7e8ef1 (sample dump: F1 8E 7E BE)
        //   server_DH_params_ok 0xd0e13b5a -> 0xd0e8075c (sample dump: 5C 07 E8 D0)
        //   'auth_DH_gen_ok' renamed to the published constructor name 'dh_gen_ok'
        $goldens = [
            'req_pq_multi' => 0xbe7e8ef1,
            'resPQ' => 0x05162463,
            'p_q_inner_data' => 0x83c95aec,
            'req_DH_params' => 0xd712e4be,
            'server_DH_params_ok' => 0xd0e8075c,
            'server_DH_inner_data' => 0xb5890dba,
            'client_DH_inner_data' => 0x6643b654,
            'set_client_DH_params' => 0xf5045f1f,
            'dh_gen_ok' => 0x3bcbf734,
            'rpc_result' => 0xf35c6d01,
            'rpc_error' => 0x2144ca19,
            'bad_server_salt' => 0xedab447b,
            'gzip_packed' => 0x3072cfa1,
            'invokeWithLayer' => 0xda9b0d0d,
            'help.getNearestDc' => 0x1fb33026,
        ];
        foreach ($goldens as $name => $id) {
            $this->assertSame($id, TLRegistry::id($name), "constructor id mismatch for {$name}");
        }
    }

    public function testUnknownNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TLRegistry::id('no.such_constructor');
    }

    public function testRegisterAddsNewLine(): void
    {
        TLRegistry::register('test_only#1cb5c415 dummy:Vector<long> = TestOnly');
        $this->assertSame(0x1cb5c415, TLRegistry::id('test_only'));
    }

    public function testSignatureReturnsCanonicalLine(): void
    {
        $this->assertSame(
            'resPQ nonce:int128 server_nonce:int128 pq:string server_public_key_fingerprints:Vector long = ResPQ',
            TLRegistry::signature('resPQ'),
        );
    }

    public function testUnknownSignatureThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TLRegistry::signature('no.such_constructor');
    }

    public function testVectorConstantIsVectorConstructorId(): void
    {
        $this->assertSame(0x1cb5c415, TLRegistry::VECTOR);
        $this->assertSame(TLRegistry::VECTOR, TLRegistry::crc32Canonical('vector t:Type # [ t ] = Vector t'));
    }
}
