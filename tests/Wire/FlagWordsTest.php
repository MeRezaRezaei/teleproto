<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Real-world schema features the codec must support: multiple flag words
 * (flags/flags2) and `?true` conditionals (presence encoded by bit only).
 */
class FlagWordsTest extends TestCase
{
    public function testSecondFlagWordAndTrueConditionalsRoundTrip(): void
    {
        TLRegistry::register(
            'testDualFlags#a1b2c3d4 flags:# first:flags.0?string flags2:# close_friend:flags2.2?true contact_require_premium:flags2.10?true last_name:flags2.0?string id:long = TestDualFlags'
        );

        $args = [
            'flags' => 0,
            'flags2' => (1 << 2) | (1 << 10), // close_friend + contact_require_premium, last_name absent
            'id' => 424242,
        ];
        $bin = TLEncoder::encodeObject('testDualFlags', $args);

        $offset = 0;
        $decoded = TLDecoder::decodeObject($bin, $offset);
        $this->assertSame(strlen($bin), $offset, 'no stray bytes consumed');
        $this->assertSame((1 << 2) | (1 << 10), $decoded['flags2']);
        $this->assertTrue($decoded['close_friend'], '?true field decodes to true with zero payload bytes');
        $this->assertTrue($decoded['contact_require_premium']);
        $this->assertArrayNotHasKey('last_name', $decoded, 'absent conditional stays absent');
        $this->assertArrayNotHasKey('first', $decoded);
        $this->assertSame(424242, $decoded['id']);

        // Present string conditional on the second flag word encodes + decodes
        $args2 = ['flags' => 0, 'flags2' => 1, 'last_name' => 'Doe', 'id' => 7];
        $bin2 = TLEncoder::encodeObject('testDualFlags', $args2);
        $off2 = 0;
        $dec2 = TLDecoder::decodeObject($bin2, $off2);
        $this->assertSame('Doe', $dec2['last_name']);
        $this->assertSame(strlen($bin2), $off2);
    }

    public function testTrueConditionalEncodesNoPayloadBytes(): void
    {
        TLRegistry::register(
            'testTrueOnly#b2c3d4e5 flags:# active:flags.0?true n:int = TestTrueOnly'
        );
        $with = TLEncoder::encodeObject('testTrueOnly', ['flags' => 1, 'active' => true, 'n' => 3]);
        $without = TLEncoder::encodeObject('testTrueOnly', ['flags' => 0, 'n' => 3]);
        $this->assertSame(strlen($without), strlen($with), '?true adds no bytes — presence is the bit itself');
    }
}
