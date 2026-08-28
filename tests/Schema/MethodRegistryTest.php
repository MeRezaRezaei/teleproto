<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use MeRezaRezaei\Teleproto\Schema\MethodRegistry;
use PHPUnit\Framework\TestCase;

class MethodRegistryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        MethodRegistry::load();
    }

    public function testLooksUpBothApis(): void
    {
        $m = MethodRegistry::get('messages.sendMessage');
        $this->assertSame('mtproto', $m->api);
        $this->assertSame('Updates', $m->returnType);
        $this->assertContains('peer', $m->paramNames());

        $b = MethodRegistry::get('sendMessage');
        $this->assertSame('bot-http', $b->api);
        $this->assertContains('chat_id', $b->required);
    }

    public function testUnknownThrows(): void
    {
        $this->assertFalse(MethodRegistry::has('no.suchMethod'));
        $this->expectException(\InvalidArgumentException::class);
        MethodRegistry::get('no.suchMethod');
    }

    public function testApiOfPinsBothApis(): void
    {
        $this->assertSame('bot-http', MethodRegistry::apiOf('sendMessage'));
        $this->assertSame('mtproto', MethodRegistry::apiOf('messages.sendMessage'));
    }

    public function testApiOfUnknownThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MethodRegistry::apiOf('no.suchMethod');
    }
}
