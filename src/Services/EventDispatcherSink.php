<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use MeRezaRezaei\Teleproto\Contracts\UpdateSinkInterface;
use MeRezaRezaei\Teleproto\Events\TelegramUpdateReceived;

/**
 * Default UpdateSink that dispatches the Laravel `TelegramUpdateReceived` event.
 *
 * Transport enrichment: when constructed without explicit transport info, the
 * sink derives it from the handle() source string — MTProto user flows pass a
 * numeric account ID as source, Bot API flows pass a `id:token` bot token —
 * so the default wiring enriches events correctly with zero configuration.
 * Explicit transport info wins over derivation.
 */
class EventDispatcherSink implements UpdateSinkInterface
{
    public function __construct(
        protected readonly string $transport = '',
        protected readonly ?int $accountId = null
    ) {}

    public function handle(array $update, ?string $source = null): bool
    {
        $accountId = $this->accountId ?? self::numericAccountId($source);
        $transport = $this->transport !== ''
            ? $this->transport
            : ($accountId !== null ? 'mtproto-user' : 'bot-http');

        TelegramUpdateReceived::dispatch($update, $source, $accountId, $transport);

        return true;
    }

    /**
     * Extract a numeric account ID from a sink source string, if it is one.
     * Bot tokens always contain a ':' separator, so they never qualify.
     */
    protected static function numericAccountId(?string $source): ?int
    {
        if ($source === null || $source === '' || !ctype_digit($source)) {
            return null;
        }

        return (int)$source;
    }
}
