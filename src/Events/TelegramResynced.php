<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Events;

use Illuminate\Support\Facades\Event;

/**
 * Event dispatched after the update sequence state has been (re-)synchronized
 * from the server, either because a gap (see TelegramGapDetected) was
 * recovered or because polling started from an unknown state (pts = 0).
 */
class TelegramResynced
{
    /**
     * @param array{pts: int, date: int, qts: int, seq: int} $state The newly adopted sequence state
     * @param int|null $accountId User account the state belongs to (if known)
     */
    final public function __construct(
        public readonly array $state,
        public readonly ?int $accountId = null
    ) {}

    /**
     * Dispatch the event if a Laravel event dispatcher is available.
     *
     * @param array{pts: int, date: int, qts: int, seq: int} $state
     */
    public static function dispatch(array $state, ?int $accountId = null): void
    {
        if (class_exists(Event::class) && Event::getFacadeApplication()) {
            Event::dispatch(new static($state, $accountId));
        }
    }
}
