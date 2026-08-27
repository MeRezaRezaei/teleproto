<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use Closure;
use MeRezaRezaei\Teleproto\Contracts\UpdateSinkInterface;
use Throwable;

/**
 * Decoupled, reusable Update Poller Service.
 * Runs polling loops for any Bot API or MTProto User account and streams raw updates into pluggable sinks.
 */
class UpdatePollerService
{
    protected UpdateSinkInterface $sink;
    /** @var (Closure(array<string, mixed>): bool)|null */
    protected ?Closure $filter = null;
    protected bool $running = true;

    public function __construct(?UpdateSinkInterface $sink = null)
    {
        $this->sink = $sink ?? new EventDispatcherSink();
    }

    public function setSink(UpdateSinkInterface $sink): self
    {
        $this->sink = $sink;
        return $this;
    }

    public function getSink(): UpdateSinkInterface
    {
        return $this->sink;
    }

    /**
     * Attach an optional predicate filter (e.g. only specific channel IDs or message types).
     *
     * @param Closure(array<string, mixed>): bool $filter
     */
    public function filter(Closure $filter): self
    {
        $this->filter = $filter;
        return $this;
    }

    /**
     * Stop the active polling loop cleanly.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Poll a Bot API client in a loop.
     *
     * @param BotClient $bot
     * @param int $timeout Long-polling timeout in seconds
     * @param int $limit Max updates per fetch
     * @param (Closure(array<string, mixed>): void)|null $onUpdate Optional inline callback
     */
    public function pollBot(BotClient $bot, int $timeout = 30, int $limit = 100, ?Closure $onUpdate = null): void
    {
        $offset = 0;
        $this->running = true;

        while ($this->running) {
            try {
                $response = $bot->call('getUpdates', [
                    'offset'  => $offset,
                    'limit'   => $limit,
                    'timeout' => $timeout,
                ]);

                $updates = $response['result'] ?? [];

                foreach ($updates as $update) {
                    $updateId = (int)($update['update_id'] ?? 0);
                    $offset = max($offset, $updateId + 1);

                    $this->processUpdate($update, $bot->botToken, $onUpdate);
                }
            } catch (Throwable $e) {
                if (!$this->running) {
                    break;
                }
                sleep(2);
            }
        }
    }

    /**
     * Poll an MTProto User account for incoming difference updates (`updates.getDifference`).
     *
     * @param UserAccountScope $user
     * @param int $pts Current persistent sequence number (0 = latest state)
     * @param int $date Current persistent timestamp
     * @param int $qts Current persistent secret chat sequence number
     * @param (Closure(array<string, mixed>): void)|null $onUpdate Optional inline callback
     */
    public function pollUser(
        UserAccountScope $user,
        int $pts = 0,
        int $date = 0,
        int $qts = 0,
        ?Closure $onUpdate = null
    ): void {
        $this->running = true;

        while ($this->running) {
            try {
                $diff = $user->call('updates.getDifference', [
                    'pts'           => $pts,
                    'pts_total_limit' => 100,
                    'date'          => $date,
                    'qts'           => $qts,
                ]);

                if (isset($diff['state']['pts'])) {
                    $pts = (int)$diff['state']['pts'];
                    $date = (int)($diff['state']['date'] ?? $date);
                    $qts = (int)($diff['state']['qts'] ?? $qts);
                }

                $newMessages = $diff['new_messages'] ?? [];
                foreach ($newMessages as $msg) {
                    $update = ['_' => 'updateNewMessage', 'message' => $msg];
                    $this->processUpdate($update, (string)($user->session->userId ?? 'user'), $onUpdate);
                }

                $otherUpdates = $diff['other_updates'] ?? [];
                foreach ($otherUpdates as $upd) {
                    $this->processUpdate($upd, (string)($user->session->userId ?? 'user'), $onUpdate);
                }

                sleep(1);
            } catch (Throwable $e) {
                if (!$this->running) {
                    break;
                }
                sleep(2);
            }
        }
    }

    /**
     * Process a single update through the filter, sink, and callback.
     *
     * @param array<string, mixed> $update
     * @param string|null $source
     * @param (Closure(array<string, mixed>): void)|null $onUpdate
     */
    public function processUpdate(array $update, ?string $source = null, ?Closure $onUpdate = null): void
    {
        if ($this->filter !== null && !($this->filter)($update)) {
            return;
        }

        // Send to pluggable sink (EventDispatcher, Redis, Postgres, etc.)
        $this->sink->handle($update, $source);

        // Invoke optional inline callback
        if ($onUpdate !== null) {
            $onUpdate($update);
        }
    }
}
