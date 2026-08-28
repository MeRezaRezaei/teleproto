<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use PHPUnit\Framework\TestCase;

class MtprotoSchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function artifact(): array
    {
        return (array) json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schema/methods-mtproto.json'), true);
    }

    public function testArtifactEnvelope(): void
    {
        $a = self::artifact();
        $this->assertTrue($a['_generated']);
        $this->assertSame('mtproto', $a['api']);
        $this->assertGreaterThan(200, $a['layer']); // layer number present
        $this->assertGreaterThan(700, count($a['methods']));
    }

    public function testSpotEntryMatchesOfficialSchema(): void
    {
        $m = self::artifact()['methods']['messages.sendMessage'];
        $this->assertSame('Updates', $m['return']);
        $this->assertSame('https://core.telegram.org/method/messages.sendMessage', $m['docs']);
        // layer-229 tdesktop signature order: no_webpage:flags.1?true leads, peer follows
        $this->assertSame('no_webpage', $m['params'][0]['name']);
        $this->assertSame('flags', $m['params'][0]['flag_word']);
        $peerIndex = array_search('peer', array_column($m['params'], 'name'), true);
        $this->assertSame('InputPeer', $m['params'][$peerIndex]['type']);
        $this->assertContains('PEER_ID_INVALID', $m['errors']); // inverted from errors.json
        $this->assertNotSame('', $m['id']);
    }

    public function testEveryEntryCarriesDocsAndErrorsList(): void
    {
        foreach (self::artifact()['methods'] as $name => $m) {
            $this->assertSame("https://core.telegram.org/method/{$name}", $m['docs'], $name);
            $this->assertIsArray($m['errors'], $name);
        }
    }
}
