<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use PHPUnit\Framework\TestCase;

class SkillFilesTest extends TestCase
{
    private const SKILL_DIR = __DIR__ . '/../../skills/telegram-methods';

    public function testSkillFileRenderedForCuratedMethod(): void
    {
        $path = dirname(__DIR__, 2) . '/skills/telegram-methods/messages.sendMessage.md';
        $this->assertFileExists($path);
        $md = (string) file_get_contents($path);
        $this->assertStringContainsString('messages.sendMessage', $md);
        $this->assertStringContainsString('https://core.telegram.org/method/messages.sendMessage', $md);
        $this->assertStringContainsString('| peer | InputPeer |', $md); // params table
        $this->assertStringContainsString('PEER_ID_INVALID', $md);      // error + hint rendered
        $this->assertStringContainsString('The provided peer id is invalid.', $md); // RpcErrorCatalog lookup hint
        $this->assertStringContainsString('Methods::messages()->sendMessage()', $md); // generated example
    }

    public function testGeneratedMarkerIsTheFirstLine(): void
    {
        $this->assertFileExists(self::SKILL_DIR . '/messages.sendMessage.md');
        $md = (string) file_get_contents(self::SKILL_DIR . '/messages.sendMessage.md');
        $lines = explode("\n", $md);
        $this->assertSame('<!-- @generated -->', $lines[0]);
    }

    public function testRequiredParamsCarryTheStarMarkerOptionalDoNot(): void
    {
        $md = (string) file_get_contents(self::SKILL_DIR . '/messages.sendMessage.md');
        $this->assertStringContainsString('| message | string | * |', $md); // no flag_word => required
        $this->assertStringContainsString('| silent | true |  |', $md);     // flag_word => optional
    }

    public function testBotApiMethodUsesTheBotsGroup(): void
    {
        $path = self::SKILL_DIR . '/sendMessage.md';
        $this->assertFileExists($path);
        $md = (string) file_get_contents($path);
        $this->assertStringContainsString('Methods::bots()->sendMessage()', $md);
        $this->assertStringContainsString('| chat_id | int | * |', $md);
    }

    public function testErrorsAreSortedDeterministically(): void
    {
        $md = (string) file_get_contents(self::SKILL_DIR . '/messages.sendMessage.md');
        $chatAdmin = strpos($md, '- `CHAT_ADMIN_REQUIRED`');
        $peerId = strpos($md, '- `PEER_ID_INVALID`');
        $this->assertIsInt($chatAdmin);
        $this->assertIsInt($peerId);
        $this->assertLessThan($peerId, $chatAdmin);
    }

    public function testMultiPlaceholderTemplateErrorRendersRealDescription(): void
    {
        // ALLOW_PAYMENT_REQUIRED_%d's description references the value twice;
        // the generator must render it (sample 30), not fall back to the bare
        // template name (the pre-2026-08-28 ArgumentCountError guard did).
        $md = (string) file_get_contents(self::SKILL_DIR . '/messages.forwardMessages.md');
        $this->assertStringContainsString(
            '`ALLOW_PAYMENT_REQUIRED_%d` — This peer charges 30 [Telegram Stars]',
            $md
        );
        $this->assertStringContainsString('smaller than 30', $md);
    }

    public function testUsageExampleChainsSettersAndDispatchesTheRequest(): void
    {
        $md = (string) file_get_contents(self::SKILL_DIR . '/messages.sendMessage.md');
        $this->assertStringContainsString("->peer(['_' => '…'])", $md);
        $this->assertStringContainsString("->message('text')", $md);
        $this->assertStringContainsString('->randomId(123)', $md);
        $this->assertStringContainsString('->toRequest();', $md);
        $this->assertStringContainsString('TeleprotoClient::dispatch($request);', $md);
    }
}
