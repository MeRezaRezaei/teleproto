<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Events;

use Illuminate\Support\Facades\Event;

/**
 * Event dispatched when the updates state machine detects a gap in the
 * update sequence and cannot deliver a contiguous stream (analogous to
 * MadelineProto's gap detection in its Update loop).
 *
 * Kinds:
 * - 'slice':    updates.getDifference returned `updates.differenceSlice`;
 *               a chunk was delivered and fetching continues from the
 *               intermediate state.
 * - 'too_long': `updates.differenceTooLong`; the requested window is so
 *               far behind that the server refuses to replay it — local
 *               pts is hard-reset to the server's pts and the skipped
 *               window is lost.
 * - 'hole':     defensive: a slice made no forward progress; re-requesting
 *               the same window would loop forever, so the window is
 *               forced forward.
 */
class TelegramGapDetected
{
    public const KIND_SLICE = 'slice';
    public const KIND_TOO_LONG = 'too_long';
    public const KIND_HOLE = 'hole';

    /**
     * @param string $kind One of the KIND_* constants
     * @param array<string, mixed> $context Gap details (pts windows, account id, server timeout, ...)
     */
    final public function __construct(
        public readonly string $kind,
        public readonly array $context = []
    ) {}

    /**
     * Dispatch the event if a Laravel event dispatcher is available.
     *
     * @param array<string, mixed> $context
     */
    public static function dispatch(string $kind, array $context = []): void
    {
        if (class_exists(Event::class) && Event::getFacadeApplication()) {
            Event::dispatch(new static($kind, $context));
        }
    }
}
