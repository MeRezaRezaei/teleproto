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
            // _dc variants required by production servers since the PFC schema
            // change (verified against MadelineProto layer-227 schema):
            'p_q_inner_data_dc' => 0xa9f55f95,
            'p_q_inner_data_temp_dc' => 0x56fddf88,
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
            // Response-side constructors the wire path can genuinely receive.
            // Provenance: nearestDc from core.telegram.org/method/help.getNearestDc
            // (nearestDc#8e1a1775); handshake rejection constructors from
            // core.telegram.org/mtproto/auth_key and the schemas of major
            // clients (server_DH_params_fail#79cb045d, dh_gen_retry#46dc1fb9,
            // dh_gen_fail#a69dae02); transient service messages from
            // core.telegram.org/mtproto/service_messages (msgs_ack#62d6b459 —
            // canonicalized brace-less `Vector long`, the registry's
            // Vector<long> convention as for resPQ — and
            // new_session_created#9ec20908).
            // Correction (round-2): the original round-1 golden registered a
            // fabricated signature 'server_DH_params_fail ... retry:int'
            // (0xc285e6a4) that exists in no client or spec; the real
            // constructor carries new_nonce_hash:int128 -> 0x79cb045d.
            'nearestDc' => 0x8e1a1775,
            'server_DH_params_fail' => 0x79cb045d,
            'dh_gen_retry' => 0x46dc1fb9,
            'dh_gen_fail' => 0xa69dae02,
            'msgs_ack' => 0x62d6b459,
            'new_session_created' => 0x9ec20908,
        ];
        foreach ($goldens as $name => $id) {
            $this->assertSame($id, TLRegistry::id($name), "constructor id mismatch for {$name}");
        }
    }

    /**
     * Guards the golden table against SCHEMA additions colliding with an
     * existing id: a collision would clobber the id => name mapping and make
     * nameOf() resolve one of the two names wrongly.
     */
    public function testGoldenIdsResolveBackToTheirOwnNames(): void
    {
        foreach ([
            'nearestDc', 'server_DH_params_fail', 'dh_gen_retry', 'dh_gen_fail',
            'msgs_ack', 'new_session_created',
            'req_pq_multi', 'resPQ', 'rpc_result', 'gzip_packed', 'help.getNearestDc',
        ] as $name) {
            $this->assertSame($name, TLRegistry::nameOf(TLRegistry::id($name)), "id collision involving {$name}");
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

    public function testSignatureOfReturnsParsedStructWithCache(): void
    {
        $sig = TLRegistry::signatureOf('auth.sendCode');
        $this->assertSame('auth.sendCode', $sig->name);
        $this->assertSame(0xa677244f, $sig->id);
        $this->assertSame('phone_number', $sig->fields[0]['name']);
        // second call returns the SAME instance (parsed once)
        $this->assertSame($sig, TLRegistry::signatureOf('auth.sendCode'));
    }

    /**
     * Wrapper routing must follow the constructor NAME ('invokeWithLayer',
     * 'initConnection'), never an 'X:Type' substring: the maxX:Type token
     * contains the substring and would hijack the whole line into the
     * degraded wrapper walk, which keeps `flags.0?Type` conditionals as
     * wire fields the strict tokenizer correctly skips as declarations.
     */
    public function testWrapperRoutingIsNameBasedNotXTypeSubstring(): void
    {
        TLRegistry::register('trapCond#5a5a5a5c maxX:Type val:flags.0?Type = TrapCond');
        $this->assertSame([], TLRegistry::signatureOf('trapCond')->fields);
    }

    public function testWrapperNameWithoutXTypeDeclarationIsRejected(): void
    {
        // secondary assertion: the name routes to the degraded parse, which
        // only makes sense for generic wrapper lines declaring X:Type
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('X:Type');
        TLRegistry::register('initConnection api_id:int = X');
    }

    public function testDegradedWrapperLineMissingEqualsThrows(): void
    {
        // (int) strpos-cast of false used to garbage-parse silently
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("degraded wrapper line missing '='");
        TLRegistry::register('invokeWithLayer X:Type layer:int query:!X');
    }

    public function testUserScopeSchemaRegistersResponseConstructors(): void
    {
        // Presence checks for response-side constructors proven live against
        // production DCs (getHistory/getDialogs/sendMessage/getState paths).
        foreach ([
            'updateMessageID', 'updates.state', 'message', 'dialog', 'userFull',
            'messages.messagesSlice', 'updateShortSentMessage', 'updatesCombined',
            'contacts.contacts', 'updateNewMessage', 'messageEntityTextUrl',
        ] as $name) {
            try {
                TLRegistry::id($name);
            } catch (\InvalidArgumentException) {
                $this->fail("{$name} must be registered for the documented user scope");
            }
        }
        $this->assertSame(0x4e90bfd6, TLRegistry::id('updateMessageID'));
    }
}
