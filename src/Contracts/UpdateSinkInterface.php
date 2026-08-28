<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Contracts;

/**
 * Contract for consuming and dispatching raw incoming Telegram updates.
 * Allows higher-level packages to plug in custom pipelines (Redis Streams, Postgres storage, Spatie DTOs, etc.).
 */
interface UpdateSinkInterface
{
    /**
     * Handle an incoming raw update from Telegram.
     *
     * Backpressure contract: return `true` when the update was processed
     * (or deliberately consumed), `false` to signal "skip / not now" so
     * producers and pipelines can react (drop, retry, defer) instead of
     * assuming silent success. Implementations must not throw to signal
     * a soft refusal.
     *
     * @param array<string, mixed> $update Raw Telegram update payload
     * @param string|null $source Identifier of the source (e.g. bot token, user account ID)
     */
    public function handle(array $update, ?string $source = null): bool;
}
