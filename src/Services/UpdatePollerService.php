<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use Closure;
use MeRezaRezaei\Teleproto\Contracts\UpdateSinkInterface;
use MeRezaRezaei\Teleproto\Events\TelegramGapDetected;
use MeRezaRezaei\Teleproto\Events\TelegramResynced;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\FloodWaitException;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use Throwable;
use UnexpectedValueException;

/**
 * Decoupled, reusable Update Poller Service.
 * Runs polling loops for any Bot API or MTProto User account and streams raw updates into pluggable sinks.
 *
 * The MTProto user loop implements the low-level updates.getDifference state
 * machine (mirrors MadelineProto's UpdateLoop/UpdatesState semantics):
 * state/intermediate_state adoption, slice continuation without refetching
 * the same window, differenceTooLong pts resets and gap/resync signalling.
 */
class UpdatePollerService
{
    /**
     * Floor for every backoff sleep: even unknown errors pause briefly
     * instead of hammering Telegram in a tight loop.
     */
    public const MIN_BACKOFF_SECONDS = 2;

    /**
     * Upper bound for a single flood backoff sleep. Telegram can demand
     * FLOOD_WAIT_X periods of many hours; clamping each sleep to one hour
     * keeps the loop responsive to stop() and avoids near-infinite hangs
     * (in production restarts and in test suites alike). The poller simply
     * retries hourly until the flood window lapses.
     */
    public const MAX_BACKOFF_SECONDS = 3600;

    /**
     * Fetch kinds returned by fetchUserDifference().
     */
    protected const KIND_COMPLETE = 'complete';
    protected const KIND_SLICE = TelegramGapDetected::KIND_SLICE;
    protected const KIND_HOLE = TelegramGapDetected::KIND_HOLE;
    protected const KIND_TOO_LONG = TelegramGapDetected::KIND_TOO_LONG;
    protected const KIND_EMPTY = 'empty';

    /**
     * Loop error reactions returned by errorLoopAction().
     */
    protected const ACTION_RETHROW = 'rethrow';
    protected const ACTION_BREAK = 'break';
    protected const ACTION_BACKOFF = 'backoff';

    protected UpdateSinkInterface $sink;
    /** @var (Closure(array<string, mixed>): bool)|null */
    protected ?Closure $filter = null;
    protected bool $running = true;

    /**
     * Persisted sequence state for the MTProto user loop, normalized to
     * {pts, date, qts, seq}. Null until seeded via setSequenceState(),
     * pollUser() arguments or an adopted server state.
     *
     * @var array{pts: int, date: int, qts: int, seq: int}|null
     */
    protected ?array $sequenceState = null;

    /**
     * Per-channel pts map (channel_id => pts) observed on updates that
     * carry both, e.g. updateNewChannelMessage / updateDeleteChannelMessages.
     *
     * @var array<int, int>
     */
    protected array $channelPts = [];

    /**
     * True while a fetch cycle started from an unknown state or has seen a
     * gap; the next terminal response completes the resync and emits
     * TelegramResynced.
     */
    protected bool $gapPending = false;

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
     * Compute how long a polling loop should back off after catching $e.
     *
     * Pure function (no side effects) so backoff policy is unit-testable:
     *
     * - Rpc\FloodWaitException (MTProto FLOOD_WAIT_X): sleep the demanded
     *   $seconds, clamped to [MIN_BACKOFF_SECONDS, MAX_BACKOFF_SECONDS].
     * - Bot API 429 (TelegramException code 429 whose description carries
     *   "retry after N", as thrown by BotClient): sleep N, same clamp.
     * - Anything else: the historical flat MIN_BACKOFF_SECONDS pause.
     */
    public static function secondsToWait(Throwable $e): int
    {
        if ($e instanceof FloodWaitException) {
            return max(self::MIN_BACKOFF_SECONDS, min($e->seconds, self::MAX_BACKOFF_SECONDS));
        }

        if ($e instanceof TelegramException && $e->getCode() === 429) {
            $retryAfter = self::parseRetryAfterSeconds($e->getMessage());
            if ($retryAfter !== null) {
                return max(self::MIN_BACKOFF_SECONDS, min($retryAfter, self::MAX_BACKOFF_SECONDS));
            }
        }

        return self::MIN_BACKOFF_SECONDS;
    }

    /**
     * Extract the N from Bot API 429 descriptions like
     * "Too Many Requests: retry after 25" using sscanf only
     * (src/ is regex-free by spec).
     */
    protected static function parseRetryAfterSeconds(string $message): ?int
    {
        $marker = 'retry after';
        $pos = stripos($message, $marker);
        if ($pos === false) {
            return null;
        }

        sscanf(substr($message, $pos + strlen($marker)), '%*[^0-9]%d', $seconds);

        return is_int($seconds) ? $seconds : null;
    }

    /**
     * Stop the active polling loop cleanly.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Sleep $seconds in stop()-responsive chunks: a FLOOD_WAIT backoff can be
     * up to MAX_BACKOFF_SECONDS (an hour), and stop() must take effect within
     * one chunk instead of after the whole uninterruptible sleep.
     */
    protected function interruptibleSleep(int $seconds): void
    {
        for ($elapsed = 0; $elapsed < $seconds && $this->running; $elapsed += self::MIN_BACKOFF_SECONDS) {
            sleep(self::MIN_BACKOFF_SECONDS);
        }
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
                $this->interruptibleSleep(self::secondsToWait($e));
            }
        }
    }

    /**
     * Poll an MTProto User account for incoming difference updates (`updates.getDifference`).
     *
     * Implements the full difference state machine (see core.telegram.org/api/updates):
     * - updates.difference / differenceEmpty: adopt state, sleep, poll again.
     * - updates.differenceSlice: adopt intermediate_state, immediately keep
     *   fetching from the advanced window (never refetch the same window).
     * - updates.differenceTooLong: hard-reset pts to the server's pts, flag
     *   the gap (TelegramGapDetected), keep polling.
     * - new_encrypted_messages (qts items) are wrapped as
     *   updateNewEncryptedMessage and streamed to the sink.
     *
     * Initial state precedence: a state previously stored via
     * setSequenceState() wins over the (defaulted) constructor arguments.
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

        if ($this->sequenceState === null) {
            // First poll from an unknown state: adopting the server's state
            // is a resync (emitted when the first terminal response lands).
            $this->gapPending = $pts === 0;
            $this->sequenceState = ['pts' => $pts, 'date' => $date, 'qts' => $qts, 'seq' => 0];
        }

        while ($this->running) {
            try {
                $kind = $this->fetchUserDifference($user, $onUpdate);

                if ($kind !== self::KIND_SLICE && $kind !== self::KIND_TOO_LONG) {
                    // Terminal response: adopt the calm polling cadence.
                    sleep(1);
                }
                // slice/too_long: loop immediately from the advanced window.
            } catch (Throwable $e) {
                $action = $this->errorLoopAction($e);
                if ($action === self::ACTION_RETHROW) {
                    throw $e;
                }
                if ($action === self::ACTION_BREAK) {
                    break;
                }
                $this->interruptibleSleep(self::secondsToWait($e));
            }
        }
    }

    /**
     * Error policy for the polling loops: how to react to a caught error.
     * 'rethrow' for unrecognized response shapes (fail fast, keep state),
     * 'break' when stop() won the race, 'backoff' otherwise.
     */
    protected function errorLoopAction(Throwable $e): string
    {
        if ($e instanceof UnexpectedValueException) {
            return self::ACTION_RETHROW;
        }

        if (!$this->running) {
            return self::ACTION_BREAK;
        }

        return self::ACTION_BACKOFF;
    }

    /**
     * Execute one updates.getDifference call and apply the response to the
     * state machine. Returns the fetch kind; slice/too_long mean "keep
     * fetching without sleeping", everything else is terminal.
     *
     * @param (Closure(array<string, mixed>): void)|null $onUpdate
     */
    protected function fetchUserDifference(UserAccountScope $user, ?Closure $onUpdate): string
    {
        $source = (string)($user->session->userId ?? 'user');
        $accountId = $user->session->userId;

        $requestedPts = $this->sequenceState['pts'];
        $diff = $user->call('updates.getDifference', [
            'pts'             => $requestedPts,
            'pts_total_limit' => 100,
            'date'            => $this->sequenceState['date'],
            'qts'             => $this->sequenceState['qts'],
        ]);

        $kind = (string)($diff['_'] ?? '');
        if ($kind === '' && (isset($diff['state']) || isset($diff['new_messages']) || isset($diff['other_updates']))) {
            // BC: constructor-less payloads (legacy canned responses / stripped
            // transports) are full differences by shape.
            $kind = 'updates.difference';
        }

        switch ($kind) {
            case 'updates.difference':
                $this->streamUserPayload($diff, $source, $onUpdate);
                $this->adoptState($diff['state'] ?? null);
                $this->finishResync($accountId);

                return self::KIND_COMPLETE;

            case 'updates.differenceSlice':
                $this->streamUserPayload($diff, $source, $onUpdate);
                $this->adoptState($diff['intermediate_state'] ?? null);

                if ($this->sequenceState['pts'] > $requestedPts) {
                    $this->gapPending = true;
                    TelegramGapDetected::dispatch(TelegramGapDetected::KIND_SLICE, [
                        'account_id' => $accountId,
                        'from_pts'   => $requestedPts,
                        'to_pts'     => $this->sequenceState['pts'],
                    ]);

                    return self::KIND_SLICE;
                }

                // No forward progress: re-requesting the same window would
                // loop forever (the historic bug). Force the window forward
                // and flag the hole.
                $this->sequenceState['pts'] = $requestedPts + 1;
                $this->gapPending = true;
                TelegramGapDetected::dispatch(TelegramGapDetected::KIND_HOLE, [
                    'account_id'       => $accountId,
                    'requested_pts'    => $requestedPts,
                    'intermediate_pts' => $this->sequenceState['pts'],
                ]);

                return self::KIND_HOLE;

            case 'updates.differenceTooLong':
                $serverPts = (int)($diff['pts'] ?? $this->sequenceState['pts']);
                TelegramGapDetected::dispatch(TelegramGapDetected::KIND_TOO_LONG, [
                    'account_id' => $accountId,
                    'local_pts'  => $this->sequenceState['pts'],
                    'server_pts' => $serverPts,
                    'timeout'    => isset($diff['timeout']) ? (int)$diff['timeout'] : null,
                ]);

                // Hard reset (may move pts backwards): the server refused to
                // replay the old window, its pts is authoritative.
                $this->sequenceState['pts'] = $serverPts;
                $this->gapPending = true;
                $this->finishResync($accountId);

                return self::KIND_TOO_LONG;

            case 'updates.differenceEmpty':
                $this->adoptState($diff);
                $this->finishResync($accountId);

                return self::KIND_EMPTY;
        }

        throw new UnexpectedValueException(
            'Unrecognized updates.getDifference response: ' . (string)($diff['_'] ?? '(no constructor)')
        );
    }

    /**
     * Stream one difference payload to the sink in MadelineProto order:
     * other_updates, new_encrypted_messages (wrapped as
     * updateNewEncryptedMessage), then new_messages (wrapped as
     * updateNewMessage / updateNewChannelMessage by peer). Updates pass
     * through raw — channel_id context is never stripped — and any update
     * carrying channel_id + pts advances the per-channel pts map.
     *
     * @param array<string, mixed> $diff
     * @param (Closure(array<string, mixed>): void)|null $onUpdate
     */
    protected function streamUserPayload(array $diff, string $source, ?Closure $onUpdate): void
    {
        foreach ($diff['other_updates'] ?? [] as $upd) {
            $this->trackChannelPts($upd);
            $this->processUpdate($upd, $source, $onUpdate);
        }

        foreach ($diff['new_encrypted_messages'] ?? [] as $encrypted) {
            $update = ['_' => 'updateNewEncryptedMessage', 'message' => $encrypted];
            $this->processUpdate($update, $source, $onUpdate);
        }

        foreach ($diff['new_messages'] ?? [] as $msg) {
            $constructor = self::isChannelMessage($msg) ? 'updateNewChannelMessage' : 'updateNewMessage';
            $update = ['_' => $constructor, 'message' => $msg];
            $this->trackChannelPts($update);
            $this->processUpdate($update, $source, $onUpdate);
        }
    }

    /**
     * Does this raw message belong to a channel/supergroup peer?
     *
     * @param array<string, mixed> $msg
     */
    protected static function isChannelMessage(array $msg): bool
    {
        return isset($msg['peer_id']['_'])
            && $msg['peer_id']['_'] === 'peerChannel'
            && isset($msg['peer_id']['channel_id']);
    }

    /**
     * Record channel pts when an update carries both a channel id (top-level
     * or inside message.peer_id) and a pts counter. Monotonic per channel.
     *
     * @param array<string, mixed> $update
     */
    protected function trackChannelPts(array $update): void
    {
        if (!isset($update['pts'])) {
            return;
        }

        $channelId = $update['channel_id'] ?? $update['message']['peer_id']['channel_id'] ?? null;
        if (!is_int($channelId)) {
            return;
        }

        $pts = (int)$update['pts'];
        if ($pts > ($this->channelPts[$channelId] ?? 0)) {
            $this->channelPts[$channelId] = $pts;
        }
    }

    /**
     * Adopt a server-side state object (updates.state from `state` or
     * `intermediate_state`, or the flat fields of differenceEmpty).
     * Monotonic: pts/qts/seq never move backwards; date always follows
     * the server. Null input is a no-op.
     *
     * @param array<string, mixed>|null $state
     */
    protected function adoptState(?array $state): void
    {
        if ($state === null) {
            return;
        }

        foreach (['pts', 'date', 'qts', 'seq'] as $key) {
            if (isset($state[$key])) {
                $value = (int)$state[$key];
                if (!isset($this->sequenceState[$key])
                    || !in_array($key, ['pts', 'qts', 'seq'], true)
                    || $value > $this->sequenceState[$key]) {
                    $this->sequenceState[$key] = $value;
                }
            }
        }
    }

    /**
     * Emit TelegramResynced if a resync was pending; clears the flag.
     * Called after every terminal response state adoption.
     */
    protected function finishResync(?int $accountId): void
    {
        if ($this->gapPending) {
            $this->gapPending = false;
            TelegramResynced::dispatch($this->getSequenceState() ?? [], $accountId);
        }
    }

    /**
     * Current persisted sequence state, if any.
     *
     * @return array{pts: int, date: int, qts: int, seq: int}|null
     */
    public function getSequenceState(): ?array
    {
        return $this->sequenceState;
    }

    /**
     * Seed the sequence state to resume from a persisted window (e.g.
     * loaded from storage between runs). Takes precedence over pollUser()'s
     * defaulted constructor arguments.
     *
     * @param array{pts?: int, date?: int, qts?: int, seq?: int} $state
     */
    public function setSequenceState(array $state): self
    {
        $this->sequenceState = [
            'pts'  => (int)($state['pts'] ?? 0),
            'date' => (int)($state['date'] ?? 0),
            'qts'  => (int)($state['qts'] ?? 0),
            'seq'  => (int)($state['seq'] ?? 0),
        ];
        $this->gapPending = false;

        return $this;
    }

    /**
     * Observed per-channel pts map (channel_id => pts). Useful to seed
     * channels.getDifference continuation windows.
     *
     * @return array<int, int>
     */
    public function getChannelPts(): array
    {
        return $this->channelPts;
    }
    /**
     * Process a single update through the filter, sink, and callback.
     * A sink returning false (not-now backpressure) skips the callback.
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
        if (!$this->sink->handle($update, $source)) {
            return;
        }

        // Invoke optional inline callback
        if ($onUpdate !== null) {
            $onUpdate($update);
        }
    }
}
