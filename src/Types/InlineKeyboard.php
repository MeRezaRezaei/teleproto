<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Types;

/**
 * Fluent builder for Telegram Bot API Inline Keyboard Markup (`inline_keyboard`).
 * Provides zero-overhead construction of interactive buttons with native PHP types.
 */
class InlineKeyboard
{
    /**
     * @var list<list<array<string, mixed>>>
     */
    protected array $rows = [];

    /**
     * Create a new InlineKeyboard builder instance.
     *
     * @param list<list<array<string, mixed>>> $rows
     */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * Adds a row of inline buttons.
     *
     * @param list<array<string, mixed>> $buttons
     */
    public function row(array $buttons): self
    {
        $this->rows[] = $buttons;
        return $this;
    }

    /**
     * Adds a single button as a new row or appends to the last row.
     *
     * @param array<string, mixed> $button
     */
    public function button(array $button, bool $newRow = false): self
    {
        if ($newRow || empty($this->rows)) {
            $this->rows[] = [$button];
        } else {
            $this->rows[count($this->rows) - 1][] = $button;
        }
        return $this;
    }

    /**
     * Helper to create an HTTP URL button.
     */
    public static function urlButton(string $text, string $url): array
    {
        return ['text' => $text, 'url' => $url];
    }

    /**
     * Helper to create a callback_data button.
     */
    public static function callbackButton(string $text, string $callbackData): array
    {
        return ['text' => $text, 'callback_data' => $callbackData];
    }

    /**
     * Helper to create a Telegram Mini App / Web App button.
     */
    public static function webAppButton(string $text, string $url): array
    {
        return ['text' => $text, 'web_app' => ['url' => $url]];
    }

    /**
     * Helper to create a login URL button.
     */
    public static function loginUrlButton(string $text, string $url, array $options = []): array
    {
        return ['text' => $text, 'login_url' => array_merge(['url' => $url], $options)];
    }

    /**
     * Helper to create an inline query switch button.
     */
    public static function switchInlineQueryButton(string $text, string $query = ''): array
    {
        return ['text' => $text, 'switch_inline_query' => $query];
    }

    /**
     * Helper to create an inline query switch current chat button.
     */
    public static function switchInlineQueryCurrentChatButton(string $text, string $query = ''): array
    {
        return ['text' => $text, 'switch_inline_query_current_chat' => $query];
    }

    /**
     * Helper to create a Telegram Pay button.
     */
    public static function payButton(string $text = 'Pay'): array
    {
        return ['text' => $text, 'pay' => true];
    }

    /**
     * Returns the array structure matching Telegram's `InlineKeyboardMarkup` specification.
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function toArray(): array
    {
        return ['inline_keyboard' => $this->rows];
    }

    /**
     * Returns JSON-encoded markup ready for Bot API calls.
     */
    public function toJson(): string
    {
        return (string)json_encode($this->toArray());
    }
}
