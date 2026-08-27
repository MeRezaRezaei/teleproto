<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use PHPUnit\Framework\TestCase;

class TLCodecTest extends TestCase
{
    public function testResPQRoundTrip(): void
    {
        $nonce = random_bytes(16);
        $serverNonce = random_bytes(16);
        $args = [
            'nonce' => $nonce,
            'server_nonce' => $serverNonce,
            'pq' => "\x01\x02",
            'server_public_key_fingerprints' => [0x0102030405060708],
        ];
        $bin = TLEncoder::encodeObject('resPQ', $args);
        $this->assertSame(pack('V', 0x05162463), substr($bin, 0, 4));

        $offset = 0;
        $decoded = TLDecoder::decodeObject($bin, $offset);
        $this->assertSame('resPQ', $decoded['_']);
        $this->assertSame($nonce, $decoded['nonce']);
        $this->assertSame($serverNonce, $decoded['server_nonce']);
        $this->assertSame("\x01\x02", $decoded['pq']);
        $this->assertSame([0x0102030405060708], $decoded['server_public_key_fingerprints']);
        $this->assertSame(strlen($bin), $offset);
    }

    /**
     * Byte-exact vector from the official worked example at
     * core.telegram.org/mtproto/samples-auth_key (step 2, server response body).
     */
    public function testResPQMatchesOfficialSampleWireBytes(): void
    {
        $bin = TLEncoder::encodeObject('resPQ', [
            'nonce' => hex2bin('51a1143fc7a3666be4be54d6890a02dc'),
            'server_nonce' => hex2bin('63248f6748214eab8a2f4cc876e11974'),
            'pq' => hex2bin('2e9cdb98c80cda4b'),
            'server_public_key_fingerprints' => [
                -3414540481677951611,
                847625836280919973,
                -4344800451088585951,
            ],
        ]);
        $expected = '63241605'
            . '51a1143fc7a3666be4be54d6890a02dc'
            . '63248f6748214eab8a2f4cc876e11974'
            . '082e9cdb98c80cda4b000000'
            . '15c4b51c' . '03000000'
            . '85fd64de851d9dd0' . 'a5b7f709355fc30b' . '216be86c022bb4c3';
        $this->assertSame($expected, bin2hex($bin));

        $offset = 0;
        $decoded = TLDecoder::decodeObject($bin, $offset);
        $this->assertSame(-3414540481677951611, $decoded['server_public_key_fingerprints'][0]);
        $this->assertSame(strlen($bin), $offset);
    }

    public function testFlagsSkippedWhenAbsent(): void
    {
        $bin = TLEncoder::encodeObject('initConnection', [
            'flags' => 0,
            'api_id' => 12345,
            'device_model' => 'test',
            'system_version' => 'test',
            'app_version' => '1.0',
            'system_lang_code' => 'en',
            'lang_pack' => '',
            'lang_code' => 'en',
            'query' => ['_' => 'help.getNearestDc'],
        ]);
        $offset = 0;
        $decoded = TLDecoder::decodeObject($bin, $offset);
        $this->assertSame('initConnection', $decoded['_']);
        $this->assertSame(12345, $decoded['api_id']);
        $this->assertSame('help.getNearestDc', $decoded['query']['_']);
    }

    public function testBadServerSaltRoundTrip(): void
    {
        $args = [
            'bad_msg_id' => 0x0102030405060708,
            'bad_msg_seqno' => 7,
            'error_code' => 48,
            'new_server_salt' => -1,
        ];
        $bin = TLEncoder::encodeObject('bad_server_salt', $args);
        $this->assertSame(pack('V', 0xedab447b), substr($bin, 0, 4));

        $offset = 0;
        $decoded = TLDecoder::decodeObject($bin, $offset);
        $this->assertSame('bad_server_salt', $decoded['_']);
        $this->assertSame($args['bad_msg_id'], $decoded['bad_msg_id']);
        $this->assertSame(7, $decoded['bad_msg_seqno']);
        $this->assertSame(48, $decoded['error_code']);
        $this->assertSame(-1, $decoded['new_server_salt']);
        $this->assertSame(strlen($bin), $offset);
    }

    public function testGzipPackedRoundTrip(): void
    {
        $bin = TLEncoder::encodeObject('gzip_packed', ['packed_data' => "\xde\xad\xbe\xef"]);
        $this->assertSame(pack('V', 0x3072cfa1), substr($bin, 0, 4));

        $offset = 0;
        $decoded = TLDecoder::decodeObject($bin, $offset);
        $this->assertSame('gzip_packed', $decoded['_']);
        $this->assertSame("\xde\xad\xbe\xef", $decoded['packed_data']);
        $this->assertSame(strlen($bin), $offset);
    }

    public function testRpcResultNestedObjectRoundTrip(): void
    {
        $bin = TLEncoder::encodeObject('rpc_result', [
            'req_msg_id' => 0x1122334455667788,
            'result' => ['_' => 'gzip_packed', 'packed_data' => 'zz'],
        ]);
        $offset = 0;
        $decoded = TLDecoder::decodeObject($bin, $offset);
        $this->assertSame('rpc_result', $decoded['_']);
        $this->assertSame(0x1122334455667788, $decoded['req_msg_id']);
        $this->assertSame('gzip_packed', $decoded['result']['_']);
        $this->assertSame('zz', $decoded['result']['packed_data']);
        $this->assertSame(strlen($bin), $offset);
    }

    public function testInvokeWithLayerNestsQuery(): void
    {
        $bin = TLEncoder::encodeObject('invokeWithLayer', [
            'layer' => 200,
            'query' => ['_' => 'help.getNearestDc'],
        ]);
        $this->assertSame(pack('V', 0xda9b0d0d), substr($bin, 0, 4));
        $this->assertSame(pack('V', 0x1fb33026), substr($bin, 8, 4));

        $offset = 0;
        $decoded = TLDecoder::decodeObject($bin, $offset);
        $this->assertSame('invokeWithLayer', $decoded['_']);
        $this->assertSame(200, $decoded['layer']);
        $this->assertSame('help.getNearestDc', $decoded['query']['_']);
        $this->assertSame(strlen($bin), $offset);
    }

    public function testUnknownConstructorThrowsWithHexId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('0xffffffff');
        $offset = 0;
        TLDecoder::decodeObject(hex2bin('ffffffff'), $offset);
    }

    public function testMissingRequiredFieldThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonce');
        TLEncoder::encodeObject('req_pq_multi', []);
    }

    public function testNameOfReverseLookup(): void
    {
        $this->assertSame('req_pq_multi', TLRegistry::nameOf(0xbe7e8ef1));
        $this->assertNull(TLRegistry::nameOf(0xdeadbeef));
    }
}
