<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as EventsDispatcher;
use Illuminate\Support\Facades\Event;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\FloodWaitException as RpcFloodWaitException;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\MTProto\Client as MTProtoClient;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Contracts\UpdateSinkInterface;
use MeRezaRezaei\Teleproto\Events\TelegramGapDetected;
use MeRezaRezaei\Teleproto\Events\TelegramResynced;
use MeRezaRezaei\Teleproto\Events\TelegramUpdateReceived;
use MeRezaRezaei\Teleproto\Services\BotClient;
use MeRezaRezaei\Teleproto\Services\EventDispatcherSink;
use MeRezaRezaei\Teleproto\Services\UpdatePollerService;
use MeRezaRezaei\Teleproto\Services\UserAccountScope;
use RuntimeException;

class UpdatePollerTest extends TestCase
{
    /**
     * Capturing sink so polled updates can be asserted without the event dispatcher.
     *
     * @param list<array{source: string|null, update: array<string, mixed>}> $storage
     */
    private function capturingSink(array &$storage): UpdateSinkInterface
    {
        return new class($storage) implements UpdateSinkInterface {
            public function __construct(public array &$storage) {}

            public function handle(array $update, ?string $source = null): bool
            {
                $this->storage[] = ['source' => $source, 'update' => $update];

                return true;
            }
        };
    }

    public function testSecondsToWaitUsesFloodWaitSeconds(): void
    {
        $this->assertSame(30, UpdatePollerService::secondsToWait(
            new RpcFloodWaitException(30, 'FLOOD_WAIT_30', 420)
        ));
    }

    public function testSecondsToWaitFloodWaitFloorIsTwoSeconds(): void
    {
        $this->assertSame(2, UpdatePollerService::secondsToWait(
            new RpcFloodWaitException(1, 'FLOOD_WAIT_1', 420)
        ));
        $this->assertSame(2, UpdatePollerService::secondsToWait(
            new RpcFloodWaitException(0, 'FLOOD_WAIT_0', 420)
        ));
    }

    public function testSecondsToWaitFloodWaitIsCappedAtOneHour(): void
    {
        $this->assertSame(3600, UpdatePollerService::secondsToWait(
            new RpcFloodWaitException(86400, 'FLOOD_WAIT_86400', 420)
        ));
        $this->assertSame(3600, UpdatePollerService::secondsToWait(
            new RpcFloodWaitException(3600, 'FLOOD_WAIT_3600', 420)
        ));
    }

    public function testSecondsToWaitReturnsExactFloodWaitSeconds(): void
    {
        $this->assertSame(45, UpdatePollerService::secondsToWait(
            new RpcFloodWaitException(45, 'FLOOD_WAIT_45', 420)
        ));
    }

    public function testSecondsToWaitParsesBotApi429RetryAfter(): void
    {
        $e = new TelegramException(
            'Telegram Bot API [429]: Too Many Requests: retry after 25',
            429
        );

        $this->assertSame(25, UpdatePollerService::secondsToWait($e));
    }

    public function testSecondsToWaitParsesRetryAfterWithExtraTrailingText(): void
    {
        $e = new TelegramException(
            'Telegram Bot API [429]: Too Many Requests: retry after 25 seconds',
            429
        );

        $this->assertSame(25, UpdatePollerService::secondsToWait($e));
    }

    public function testSecondsToWaitBotApi429RetryAfterClamped(): void
    {
        $huge = new TelegramException(
            'Telegram Bot API [429]: Too Many Requests: retry after 7200',
            429
        );
        $tiny = new TelegramException(
            'Telegram Bot API [429]: Too Many Requests: retry after 1',
            429
        );

        $this->assertSame(3600, UpdatePollerService::secondsToWait($huge));
        $this->assertSame(2, UpdatePollerService::secondsToWait($tiny));
    }

    public function testSecondsToWaitBotApi429WithoutRetryAfterDefaultsToTwo(): void
    {
        $e = new TelegramException('Telegram Bot API [429]: Too Many Requests', 429);

        $this->assertSame(2, UpdatePollerService::secondsToWait($e));
    }

    public function testSecondsToWaitNon429WithRetryAfterTextDefaultsToTwo(): void
    {
        $e = new TelegramException(
            'Telegram Bot API [400]: Too Many Requests: retry after 25',
            400
        );

        $this->assertSame(2, UpdatePollerService::secondsToWait($e));
    }

    public function testSecondsToWaitGenericThrowableDefaultsToTwo(): void
    {
        $this->assertSame(2, UpdatePollerService::secondsToWait(new RuntimeException('boom')));
    }

    /**
     * Wiring test: pollBot's catch block must honor FloodWaitException::$seconds
     * (old behaviour slept a flat 2s). Uses seconds=3 and measures the gap between
     * the flood-wait throw and the next call so a flat 2s sleep fails the assertion.
     */
    public function testPollBotBacksOffForFloodWaitSeconds(): void
    {
        $captured = [];
        $poller = new UpdatePollerService($this->capturingSink($captured));

        $bot = new class extends BotClient {
            public int $attempts = 0;
            public float $backoffSeconds = 0.0;
            private float $thrownAt = 0.0;
            public ?Closure $halt = null;

            public function __construct()
            {
                parent::__construct('test:token');
            }

            public function call(string $method, array $params = []): array
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    $this->thrownAt = (float)hrtime(true);
                    throw new RpcFloodWaitException(3, 'FLOOD_WAIT_3', 420);
                }
                if ($this->attempts === 2) {
                    $this->backoffSeconds = ((float)hrtime(true) - $this->thrownAt) / 1e9;
                    return ['ok' => true, 'result' => [
                        ['update_id' => 10, 'message' => ['text' => 'hi']],
                    ]];
                }
                if ($this->halt !== null) {
                    ($this->halt)();
                }
                throw new RuntimeException('halt polling');
            }
        };
        $bot->halt = fn () => $poller->stop();

        $poller->pollBot($bot, timeout: 0, limit: 1);

        $this->assertSame(3, $bot->attempts);
        $this->assertCount(1, $captured);
        $this->assertSame('hi', $captured[0]['update']['message']['text']);
        $this->assertGreaterThanOrEqual(3.0, $bot->backoffSeconds);
    }

    /**
     * Wiring test: pollUser's catch block must honor FloodWaitException::$seconds too.
     * Measures the gap between the flood-wait throw and the next call, independently
     * of pollUser's inter-request sleep(1).
     */
    public function testPollUserBacksOffForFloodWaitSeconds(): void
    {
        $captured = [];
        $poller = new UpdatePollerService($this->capturingSink($captured));

        $user = new class extends UserAccountScope {
            public int $attempts = 0;
            public float $backoffSeconds = 0.0;
            private float $thrownAt = 0.0;
            public ?Closure $halt = null;

            public function __construct()
            {
                parent::__construct(
                    new MTProtoClient(apiId: 1, apiHash: 'hash', live: false),
                    new SessionData(dcId: 2, authKey: random_bytes(256))
                );
            }

            public function call(string $method, array $params = []): array
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    $this->thrownAt = (float)hrtime(true);
                    throw new RpcFloodWaitException(3, 'FLOOD_WAIT_3', 420);
                }
                if ($this->attempts === 2) {
                    $this->backoffSeconds = ((float)hrtime(true) - $this->thrownAt) / 1e9;
                    return [
                        'state' => ['pts' => 10, 'date' => 20, 'qts' => 0],
                        'new_messages' => [['id' => 7, 'message' => 'hi']],
                        'other_updates' => [],
                    ];
                }
                if ($this->halt !== null) {
                    ($this->halt)();
                }
                throw new RuntimeException('halt polling');
            }
        };
        $user->halt = fn () => $poller->stop();

        $poller->pollUser($user);

        $this->assertSame(3, $user->attempts);
        $this->assertCount(1, $captured);
        $this->assertSame('updateNewMessage', $captured[0]['update']['_']);
        $this->assertGreaterThanOrEqual(3.0, $user->backoffSeconds);
    }

    // ------------------------------------------------------------------
    // TelegramUpdateReceived enrichment (BC constructor defaults)
    // ------------------------------------------------------------------

    /**
     * Run $body with the Event facade wired to an in-memory dispatcher,
     * restoring the previous application afterwards.
     *
     * @template T
     * @param Closure(EventsDispatcher): T $body
     * @return T
     */
    private function withEventDispatcher(Closure $body): mixed
    {
        $previousApp = Event::getFacadeApplication();

        $app = new Container();
        $dispatcher = new EventsDispatcher($app);
        Event::setFacadeApplication($app);
        Event::swap($dispatcher);

        try {
            return $body($dispatcher);
        } finally {
            Event::setFacadeApplication($previousApp);
            Event::clearResolvedInstances();
        }
    }

    public function testTelegramUpdateReceivedBcConstructorDefaults(): void
    {
        $rawUpdate = ['update_id' => 5, 'message' => ['text' => 'hi']];
        $event = new TelegramUpdateReceived($rawUpdate, '123456:BOT');

        $this->assertNull($event->accountId);
        $this->assertSame('bot-http', $event->source);
        // Legacy getters keep working.
        $this->assertSame(5, $event->getUpdateId());
        $this->assertSame('hi', $event->getMessage()['text']);
        $this->assertNull($event->getCallbackQuery());
    }

    public function testTelegramUpdateReceivedExplicitEnrichment(): void
    {
        $rawUpdate = ['update_id' => 6];
        $event = new TelegramUpdateReceived($rawUpdate, null, 501558149, 'mtproto-user');

        $this->assertSame(501558149, $event->accountId);
        $this->assertSame('mtproto-user', $event->source);
    }

    public function testEventDispatcherSinkReturnsTrueAndDerivesUserTransport(): void
    {
        $received = [];

        $this->withEventDispatcher(function (EventsDispatcher $dispatcher) use (&$received): void {
            $dispatcher->listen(TelegramUpdateReceived::class, function (TelegramUpdateReceived $e) use (&$received): void {
                $received[] = $e;
            });

            $sink = new EventDispatcherSink();
            $update = ['_' => 'updateNewMessage', 'message' => ['id' => 1]];

            $this->assertTrue($sink->handle($update, '501558149'));
        });

        $this->assertCount(1, $received);
        $this->assertSame(501558149, $received[0]->accountId);
        $this->assertSame('mtproto-user', $received[0]->source);
        $this->assertSame('501558149', $received[0]->botToken);
    }

    public function testEventDispatcherSinkDerivesBotHttpForBotTokens(): void
    {
        $received = [];

        $this->withEventDispatcher(function (EventsDispatcher $dispatcher) use (&$received): void {
            $dispatcher->listen(TelegramUpdateReceived::class, function (TelegramUpdateReceived $e) use (&$received): void {
                $received[] = $e;
            });

            $sink = new EventDispatcherSink();
            $this->assertTrue($sink->handle(['update_id' => 1], '123456:ABC-DEF'));
            $this->assertTrue($sink->handle(['update_id' => 2]));
        });

        $this->assertCount(2, $received);
        foreach ($received as $event) {
            $this->assertNull($event->accountId);
            $this->assertSame('bot-http', $event->source);
        }
    }

    public function testEventDispatcherSinkExplicitTransportWins(): void
    {
        $received = [];

        $this->withEventDispatcher(function (EventsDispatcher $dispatcher) use (&$received): void {
            $dispatcher->listen(TelegramUpdateReceived::class, function (TelegramUpdateReceived $e) use (&$received): void {
                $received[] = $e;
            });

            $sink = new EventDispatcherSink('mtproto-user', 42);
            $this->assertTrue($sink->handle(['update_id' => 1], 'ignored-token'));
        });

        $this->assertSame(42, $received[0]->accountId);
        $this->assertSame('mtproto-user', $received[0]->source);
    }

    public function testGapAndResyncedDispatchIsGuardedWithoutLaravel(): void
    {
        $guard = Event::getFacadeApplication();
        Event::setFacadeApplication(null);
        Event::clearResolvedInstances();

        try {
            TelegramGapDetected::dispatch('slice', ['from_pts' => 1, 'to_pts' => 2]);
            TelegramResynced::dispatch(['pts' => 2, 'date' => 0, 'qts' => 0, 'seq' => 0]);
            $this->assertFalse((bool)Event::getFacadeApplication());
        } finally {
            Event::setFacadeApplication($guard);
        }
    }

    public function testProcessUpdateSinkRefusalSkipsCallbackButNotOtherUpdates(): void
    {
        $captured = [];
        $refused = [];
        $sink = new class($captured, $refused) implements UpdateSinkInterface {
            public function __construct(private array &$captured, private array &$refused) {}

            public function handle(array $update, ?string $source = null): bool
            {
                $want = ($update['message']['id'] ?? 0) !== 1;
                if ($want) {
                    $this->captured[] = $update;
                } else {
                    $this->refused[] = $update;
                }

                return $want;
            }
        };

        $poller = new UpdatePollerService($sink);
        $seen = [];
        $onUpdate = function (array $update) use (&$seen): void {
            $seen[] = $update['message']['id'];
        };

        $poller->processUpdate(['message' => ['id' => 1]], 'a', $onUpdate);
        $poller->processUpdate(['message' => ['id' => 2]], 'a', $onUpdate);

        $this->assertCount(1, $refused);
        $this->assertCount(1, $captured);
        $this->assertSame([2], $seen);
    }
}
