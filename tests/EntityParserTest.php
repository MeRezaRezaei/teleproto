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

    public function testMarkdownToEntitiesFormatting(): void
    {
        $markdown = '*Hello* _World_ `code` ~strike~ ||spoiler|| [Telegram](https://telegram.org)';
        $result = EntityParser::markdownToEntities($markdown);

        $this->assertEquals('Hello World code strike spoiler Telegram', $result['text']);
        $this->assertCount(6, $result['entities']);

        $this->assertEquals('messageEntityBold', $result['entities'][0]['_']);
        $this->assertEquals(0, $result['entities'][0]['offset']);
        $this->assertEquals(5, $result['entities'][0]['length']);

        $this->assertEquals('messageEntityItalic', $result['entities'][1]['_']);
        $this->assertEquals('messageEntityCode', $result['entities'][2]['_']);
        $this->assertEquals('messageEntityStrike', $result['entities'][3]['_']);
        $this->assertEquals('messageEntitySpoiler', $result['entities'][4]['_']);
        $this->assertEquals('messageEntityTextUrl', $result['entities'][5]['_']);
        $this->assertEquals('https://telegram.org', $result['entities'][5]['url']);
    }

    public function testMarkdownEscapedCharactersAndPreBlocks(): void
    {
        $markdown = "Notice: \*not bold\* and [link](https://tg.org)\n```php\n\$var = 1;\n```";
        $result = EntityParser::markdownToEntities($markdown);

        $this->assertEquals("Notice: *not bold* and link\n\$var = 1;\n", $result['text']);
        $this->assertCount(2, $result['entities']);

        $this->assertEquals('messageEntityTextUrl', $result['entities'][0]['_']);
        $this->assertEquals('https://tg.org', $result['entities'][0]['url']);

        $this->assertEquals('messageEntityPre', $result['entities'][1]['_']);
        $this->assertEquals('php', $result['entities'][1]['language']);
    }
}
