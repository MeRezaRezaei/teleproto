<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\TL\TLSignatureParser;
use PHPUnit\Framework\TestCase;

class TLSignatureParserTest extends TestCase
{
    public function testPlainConstructorWithExplicitId(): void
    {
        $sig = TLSignatureParser::parse('auth.sendCode#a677244f phone_number:string api_id:int api_hash:string settings:CodeSettings = auth.SentCode');
        $this->assertSame('auth.sendCode', $sig->name);
        $this->assertSame(0xa677244f, $sig->id);
        $this->assertTrue($sig->hasExplicitId);
        $this->assertSame('auth.SentCode', $sig->returnType);
        $this->assertCount(4, $sig->fields);
        $this->assertSame(['name' => 'phone_number', 'type' => 'string', 'flagWord' => null, 'bit' => null], $sig->fields[0]);
    }

    public function testIdlessLineComputesNothingAndFlagsZero(): void
    {
        $sig = TLSignatureParser::parse('msgs_ack msg_ids:Vector<long> = MsgsAck');
        $this->assertSame('msgs_ack', $sig->name);
        $this->assertFalse($sig->hasExplicitId);
        $this->assertSame('Vector<long>', $sig->fields[0]['type']);
    }

    public function testConditionalFieldParsesFlagWordAndBit(): void
    {
        $sig = TLSignatureParser::parse('x#deadbeef f:flags.0?string flags:# = X');
        // 'flags' word itself must appear as a field with type '#'
        $this->assertSame(['name' => 'f', 'type' => 'string', 'flagWord' => 'flags', 'bit' => 0], $sig->fields[0]);
        $this->assertSame(['name' => 'flags', 'type' => '#', 'flagWord' => null, 'bit' => null], $sig->fields[1]);
    }

    public function testBareVectorTwoTokenFormNormalizes(): void
    {
        $sig = TLSignatureParser::parse('help.getNearestDc = NearestDc');
        $this->assertSame([], $sig->fields);
        $this->assertSame('NearestDc', $sig->returnType);
        $sig2 = TLSignatureParser::parse('users.getUsers#d91a548 id:Vector InputUser = Vector User');
        // bare two-token Vector: `Vector InputUser` -> Vector<InputUser>; trailing return `Vector User` -> Vector<User>
        $this->assertSame('Vector<InputUser>', $sig2->fields[0]['type']);
        $this->assertSame('Vector<User>', $sig2->returnType);
    }

    public function testSecondFlagWord(): void
    {
        $sig = TLSignatureParser::parse('u#1 flags:# flags2:# last:flags2.0?string = User');
        $this->assertSame('flags2', $sig->fields[2]['flagWord']);
        $this->assertSame(0, $sig->fields[2]['bit']);
    }

    public function testMalformedLinesThrowWithColumn(): void
    {
        foreach ([
            'name#zz bad input' => 'id',
            'name field-without-col = X' => "':'",
            'name = ' => 'return',
            '' => 'name',
        ] as $line => $needle) {
            try {
                TLSignatureParser::parse($line);
                $this->fail("no exception for: {$line}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('col ', $e->getMessage(), $line);
                $this->assertStringContainsString($needle, $e->getMessage(), $line);
            }
        }
    }
}
