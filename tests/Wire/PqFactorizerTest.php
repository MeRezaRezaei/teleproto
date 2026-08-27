<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Crypto\PqFactorizer;
use PHPUnit\Framework\TestCase;

class PqFactorizerTest extends TestCase
{
    /**
     * Known-good vectors from the official MTProto docs
     * (core.telegram.org/mtproto/samples-authkey):
     * P = 494C553B, Q = 53911073, pq = P*Q = 17ED48941A08F981.
     */
    public function testOfficialVector(): void
    {
        $pqHex = '17ED48941A08F981';
        [$p, $q] = PqFactorizer::factorize(hex2bin($pqHex));
        // docs: P = 494C553B, Q = 53911073
        $this->assertSame('494C553B', strtoupper(bin2hex($p)));
        $this->assertSame('53911073', strtoupper(bin2hex($q)));
    }

    public function testSmallSemiprime(): void
    {
        [$p2, $q2] = PqFactorizer::factorize("\x0f");
        $this->assertSame(3, (int)hexdec(bin2hex($p2)));
        $this->assertSame(5, (int)hexdec(bin2hex($q2)));
    }
}
