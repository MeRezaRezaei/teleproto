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
     * @param int|null $accountId Numeric user account ID (MTProto user flows, if known)
     * @param string $source Transport this update arrived on: 'mtproto-user'|'bot-http'
     */
    final public function __construct(
        public array $update,
        public ?string $botToken = null,
        public readonly ?int $accountId = null,
        public readonly string $source = 'bot-http'
    ) {}

    /**
     * Dispatch the event with the given arguments.
     *
     * @param array<string, mixed> $update
     * @param string|null $botToken
     * @param int|null $accountId
     * @param string $source
     */
    public static function dispatch(
        array $update,
        ?string $botToken = null,
        ?int $accountId = null,
        string $source = 'bot-http'
    ): void {
        if (class_exists(Event::class) && Event::getFacadeApplication()) {
            Event::dispatch(new static($update, $botToken, $accountId, $source));
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
