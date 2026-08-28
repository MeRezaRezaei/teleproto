<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Events;

use Illuminate\Support\Facades\Event;

/**
 * Event dispatched whenever an incoming Telegram update is received via Webhook or Long Polling.
 */
class TelegramUpdateReceived
{
    /**
     * @param array<string, mixed> $update Raw Telegram Update object
     * @param string|null $botToken Bot token that received the update (if known)
     */
    final public function __construct(
        public array $update,
        public ?string $botToken = null
    ) {}

    /**
     * Dispatch the event with the given arguments.
     *
     * @param array<string, mixed> $update
     * @param string|null $botToken
     */
    public static function dispatch(array $update, ?string $botToken = null): void
    {
        if (class_exists(Event::class) && Event::getFacadeApplication()) {
            Event::dispatch(new static($update, $botToken));
        }
    }

    /**
     * Get update ID.
     */
    public function getUpdateId(): int
    {
        return (int)($this->update['update_id'] ?? 0);
    }

    /**
     * Get message payload if present.
     *
     * @return array<string, mixed>|null
     */
    public function getMessage(): ?array
    {
        return $this->update['message'] ?? null;
    }

    /**
     * Get callback query payload if present.
     *
     * @return array<string, mixed>|null
     */
    public function getCallbackQuery(): ?array
    {
        return $this->update['callback_query'] ?? null;
    }
}
