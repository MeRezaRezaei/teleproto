<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use MeRezaRezaei\Teleproto\Contracts\UpdateSinkInterface;
use MeRezaRezaei\Teleproto\Events\TelegramUpdateReceived;

/**
 * Default UpdateSink that dispatches the Laravel `TelegramUpdateReceived` event.
 */
class EventDispatcherSink implements UpdateSinkInterface
{
    public function handle(array $update, ?string $source = null): void
    {
        TelegramUpdateReceived::dispatch($update, $source);
    }
}
