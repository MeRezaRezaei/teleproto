<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests;

use PHPUnit\Framework\TestCase;
use MeRezaRezaei\Teleproto\Entities\EntityParser;

class EntityParserTest extends TestCase
{
    public function testBasicHtmlFormattingToEntities(): void
    {
        $html = '<b>Hello</b> <i>World</i> <code>code</code>';
        $result = EntityParser::htmlToEntities($html);

        $this->assertEquals('Hello World code', $result['text']);
        $this->assertCount(3, $result['entities']);

        // Bold
        $this->assertEquals('messageEntityBold', $result['entities'][0]['_']);
        $this->assertEquals(0, $result['entities'][0]['offset']);
        $this->assertEquals(5, $result['entities'][0]['length']);

        // Italic
        $this->assertEquals('messageEntityItalic', $result['entities'][1]['_']);
        $this->assertEquals(6, $result['entities'][1]['offset']);
        $this->assertEquals(5, $result['entities'][1]['length']);

        // Code
        $this->assertEquals('messageEntityCode', $result['entities'][2]['_']);
        $this->assertEquals(12, $result['entities'][2]['offset']);
        $this->assertEquals(4, $result['entities'][2]['length']);
    }

    public function testEmojiUtf16SurrogatePairOffsets(): void
    {
        // 4-byte emoji 😀 is 2 UTF-16 code units
        $html = '😀 <b>Bold after emoji</b>';
        $result = EntityParser::htmlToEntities($html);

        $this->assertEquals('😀 Bold after emoji', $result['text']);
        $this->assertCount(1, $result['entities']);

        // Offset must be 3 (2 code units for emoji + 1 space)
        $this->assertEquals('messageEntityBold', $result['entities'][0]['_']);
        $this->assertEquals(3, $result['entities'][0]['offset']);
        $this->assertEquals(16, $result['entities'][0]['length']);
    }

    public function testTextUrlWithAttributes(): void
    {
        $html = 'Check <a href="https://telegram.org">Telegram</a> now!';
        $result = EntityParser::htmlToEntities($html);

        $this->assertEquals('Check Telegram now!', $result['text']);
        $this->assertCount(1, $result['entities']);

        $this->assertEquals('messageEntityTextUrl', $result['entities'][0]['_']);
        $this->assertEquals('https://telegram.org', $result['entities'][0]['url']);
        $this->assertEquals(6, $result['entities'][0]['offset']);
        $this->assertEquals(8, $result['entities'][0]['length']);
    }
}
