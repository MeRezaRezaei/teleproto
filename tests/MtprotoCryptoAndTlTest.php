<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests;

use PHPUnit\Framework\TestCase;
use MeRezaRezaei\Teleproto\MTProto\Crypto\AesIge;
use MeRezaRezaei\Teleproto\MTProto\TL\TLSerializer;

class MtprotoCryptoAndTlTest extends TestCase
{
    public function testAesIgeEncryptAndDecrypt(): void
    {
        $key = random_bytes(32);
        $iv = random_bytes(32);
        // IGE inputs must be block-aligned (PacketCodec pads before calling)
        $plaintext = str_pad('Secret Telegram MTProto message payload 1234567890!', 64, '.');

        $cipher = AesIge::encrypt($plaintext, $key, $iv);
        $this->assertSame(strlen($plaintext), strlen($cipher));
        $decrypted = AesIge::decrypt($cipher, $key, $iv);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testTLStringPackingAndUnpacking(): void
    {
        $testStrings = [
            'short',
            'Hello world! A bit longer string that spans several words.',
            str_repeat('A', 300), // > 253 bytes to trigger 4-byte length prefix
        ];

        foreach ($testStrings as $original) {
            $packed = TLSerializer::packString($original);
            $offset = 0;
            $unpacked = TLSerializer::unpackString($packed, $offset);

            $this->assertEquals($original, $unpacked);
            $this->assertEquals(strlen($packed), $offset);
        }
    }

    public function testPasswordCalculatorSrpProof(): void
    {
        $accountPassword = [
            'has_password' => true,
            'current_algo' => [
                'g' => 3,
                'p' => str_repeat("\xFF", 255) . "\xED",
                'salt1' => 'salt1_test_bytes',
                'salt2' => 'salt2_test_bytes',
            ],
            'srp_B' => str_repeat("\xBB", 256),
            'srp_id' => 123456789,
        ];

        $proof = \MeRezaRezaei\Teleproto\MTProto\Crypto\PasswordCalculator::computeSrpProof(
            $accountPassword,
            'my_secure_cloud_password'
        );

        $this->assertEquals(123456789, $proof['srp_id']);
        $this->assertNotEmpty($proof['A']);
        $this->assertEquals(32, strlen($proof['M1']));
    }

    public function testTLVectorAndPrimitives(): void
    {
        $numbers = [10, 20, 30, 40, 50];
        $packed = TLSerializer::packVector($numbers, fn($n) => TLSerializer::packInt($n));

        $offset = 0;
        $unpacked = TLSerializer::unpackVector($packed, $offset, fn($buf, &$off) => TLSerializer::unpackInt($buf, $off));

        $this->assertEquals($numbers, $unpacked);
        $this->assertEquals(strlen($packed), $offset);

        // Double
        $d = 3.1415926535;
        $packedD = TLSerializer::packDouble($d);
        $offD = 0;
        $unpackedD = TLSerializer::unpackDouble($packedD, $offD);
        $this->assertEqualsWithDelta($d, $unpackedD, 0.00001);
    }

    public function testMTProtoPacketCodecEncryptionAndDecryption(): void
    {
        $authKey = random_bytes(256);
        $sessionId = 1234567890;
        $serverSalt = 9876543210;
        $seqNo = 2;
        $payload = TLSerializer::packString('MTProto 2.0 Binary Wire Packet Payload');

        // Client -> Server packet
        $packet = \MeRezaRezaei\Teleproto\MTProto\Crypto\PacketCodec::encryptPacket(
            payload: $payload,
            authKey: $authKey,
            sessionId: $sessionId,
            serverSalt: $serverSalt,
            seqNo: $seqNo,
            toServer: true
        );

        $this->assertGreaterThan(40, strlen($packet));

        $decrypted = \MeRezaRezaei\Teleproto\MTProto\Crypto\PacketCodec::decryptPacket(
            $packet,
            $authKey,
            fromServer: false
        );

        $this->assertEquals($sessionId, $decrypted['session_id']);
        $this->assertEquals($serverSalt, $decrypted['server_salt']);
        $this->assertEquals($seqNo, $decrypted['seq_no']);
        $this->assertEquals($payload, $decrypted['payload']);
    }

    public function testMTProtoInputTypesBuilders(): void
    {
        $userInput = \MeRezaRezaei\Teleproto\Types\InputUser::user(12345, 'hash_abc');
        $this->assertEquals('inputUser', $userInput['_']);
        $this->assertEquals(12345, $userInput['user_id']);

        $channelInput = \MeRezaRezaei\Teleproto\Types\InputChannel::channel(67890, 'hash_xyz');
        $this->assertEquals('inputChannel', $channelInput['_']);
        $this->assertEquals(67890, $channelInput['channel_id']);

        $fileInput = \MeRezaRezaei\Teleproto\Types\InputFile::file(111, 4, 'photo.jpg', 'md5_sum');
        $this->assertEquals('inputFile', $fileInput['_']);
        $this->assertEquals(4, $fileInput['parts']);

        $bigFileInput = \MeRezaRezaei\Teleproto\Types\InputFile::big(222, 100, 'video.mp4');
        $this->assertEquals('inputFileBig', $bigFileInput['_']);
        $this->assertEquals(100, $bigFileInput['parts']);
    }
}
