<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as EventsDispatcher;
use Illuminate\Support\Facades\Event;
use MeRezaRezaei\Teleproto\Contracts\UpdateSinkInterface;
use MeRezaRezaei\Teleproto\Events\TelegramGapDetected;
use MeRezaRezaei\Teleproto\Events\TelegramResynced;
use MeRezaRezaei\Teleproto\MTProto\Client as MTProtoClient;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Services\UpdatePollerService;
use MeRezaRezaei\Teleproto\Services\UserAccountScope;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Low-level getDifference state machine tests: canned response sequences
 * (normal / slice / tooLong / empty + qts items) driven through a fake
 * UserAccountScope whose call() is stubbed. No network, no handler logic.
 *
 * @internal
 */
final class UpdateStateMachineTest extends TestCase
{
    private const USER_ID = 501;

    /**
     * Capturing sink: records every handled update, optionally refuses the
     * first N handles (false = not-now backpressure).
     *
     * @param list<array{source: string|null, update: array<string, mixed>}> $storage
     */
    private function capturingSink(array &$storage, int $refuseFirst = 0): UpdateSinkInterface
    {
        return new class($storage, $refuseFirst) implements UpdateSinkInterface {
            public int $handled = 0;

            public function __construct(public array &$storage, private readonly int $refuseFirst) {}

            public function handle(array $update, ?string $source = null): bool
            {
                $this->handled++;
                if ($this->handled <= $this->refuseFirst) {
                    return false;
                }
                $this->storage[] = ['source' => $source, 'update' => $update];

                return true;
            }
        };
    }

    /**
     * Fake user scope replaying a canned sequence of updates.getDifference
     * responses via a stubbed call(). The halt callback fires when the
     * sequence is exhausted; the exhausting call throws and is not recorded.
     *
     * @param list<array<string, mixed>> $sequence
     * @param list<array{method: string, params: array<string, mixed>}> $calls
     */
    private function fakeScope(array $sequence, array &$calls, ?Closure $halt): UserAccountScope
    {
        return new class($sequence, $halt, $calls) extends UserAccountScope {
            public function __construct(
                private readonly array $sequence,
                private readonly ?Closure $halt,
                private array &$calls
            ) {
                parent::__construct(
                    new MTProtoClient(apiId: 1, apiHash: 'hash', live: false),
                    new SessionData(dcId: 2, authKey: random_bytes(256), userId: 501)
                );
            }

            public function call(string $method, array $params = []): array
            {
                if (count($this->calls) >= count($this->sequence)) {
                    if ($this->halt !== null) {
                        ($this->halt)();
                    }
                    throw new \RuntimeException('halt polling');
                }

                $this->calls[] = ['method' => $method, 'params' => $params];

                return $this->sequence[count($this->calls) - 1];
            }
        };
    }

    /**
     * Run $body with the Laravel Event facade wired to a real in-memory
     * dispatcher. Restores the previous facade application afterwards
     * (global state hygiene).
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

    /**
     * @param list<array<string, mixed>> $gaps
     * @param list<array<string, mixed>> $resyncs
     */
    private function listenForGapAndResync(EventsDispatcher $dispatcher, array &$gaps, array &$resyncs): void
    {
        $dispatcher->listen(TelegramGapDetected::class, function (TelegramGapDetected $e) use (&$gaps): void {
            $gaps[] = ['kind' => $e->kind, 'context' => $e->context];
        });
        $dispatcher->listen(TelegramResynced::class, function (TelegramResynced $e) use (&$resyncs): void {
            $resyncs[] = ['state' => $e->state, 'accountId' => $e->accountId];
        });
    }

    // ------------------------------------------------------------------
    // State exposure: getSequenceState / setSequenceState
    // ------------------------------------------------------------------

    public function testGetSequenceStateIsNullByDefault(): void
    {
        $this->assertNull((new UpdatePollerService())->getSequenceState());
    }

    public function testSetSequenceStateRoundTripsAndNormalizes(): void
    {
        $poller = new UpdatePollerService();
        $poller->setSequenceState(['pts' => 5, 'date' => 100, 'qts' => 2]);

        $this->assertSame(
            ['pts' => 5, 'date' => 100, 'qts' => 2, 'seq' => 0],
            $poller->getSequenceState()
        );
    }

    public function testSetSequenceStateResumesPollingFromStoredWindow(): void
    {
        $captured = [];
        $calls = [];
        $poller = new UpdatePollerService($this->capturingSink($captured));
        $poller->setSequenceState(['pts' => 55, 'date' => 900, 'qts' => 4, 'seq' => 12]);
        $scope = $this->fakeScope(
            [['_' => 'updates.differenceEmpty', 'pts' => 55, 'date' => 900, 'seq' => 12, 'qts' => 4]],
            $calls,
            fn () => $poller->stop()
        );

        $poller->pollUser($scope);

        $this->assertCount(1, $calls);
        $this->assertSame(55, $calls[0]['params']['pts']);
        $this->assertSame(900, $calls[0]['params']['date']);
        $this->assertSame(4, $calls[0]['params']['qts']);
    }

    // ------------------------------------------------------------------
    // updates.difference: state adoption + payload streaming
    // ------------------------------------------------------------------

    public function testDifferenceAdoptsStateAndStreamsEveryPayloadKind(): void
    {
        $captured = [];
        $calls = [];
        $poller = new UpdatePollerService($this->capturingSink($captured));
        $scope = $this->fakeScope([
            [
                '_' => 'updates.difference',
                'state' => ['_' => 'updates.state', 'pts' => 10, 'date' => 20, 'qts' => 3, 'seq' => 1],
                'new_messages' => [['id' => 7, 'message' => 'hi', 'peer_id' => ['_' => 'peerUser', 'user_id' => 2]]],
                'other_updates' => [['_' => 'updateReadHistoryInbox', 'pts' => 9, 'pts_count' => 1]],
                'new_encrypted_messages' => [['_' => 'encryptedMessage', 'random_id' => 77, 'chat_id' => 5]],
            ],
        ], $calls, fn () => $poller->stop());

        $poller->pollUser($scope, pts: 8, date: 11, qts: 1);

        $this->assertCount(1, $calls);
        $this->assertSame(8, $calls[0]['params']['pts']);
        $this->assertSame(11, $calls[0]['params']['date']);
        $this->assertSame(1, $calls[0]['params']['qts']);

        // MadelineProto ordering: other_updates, new_encrypted_messages, new_messages
        $kinds = array_map(fn (array $c): string => $c['update']['_'], $captured);
        $this->assertSame(['updateReadHistoryInbox', 'updateNewEncryptedMessage', 'updateNewMessage'], $kinds);

        $this->assertSame(
            ['_' => 'updateNewEncryptedMessage', 'message' => ['_' => 'encryptedMessage', 'random_id' => 77, 'chat_id' => 5]],
            $captured[1]['update']
        );

        $this->assertSame(
            ['pts' => 10, 'date' => 20, 'qts' => 3, 'seq' => 1],
            $poller->getSequenceState()
        );

        foreach ($captured as $entry) {
            $this->assertSame((string) self::USER_ID, $entry['source']);
        }
    }

    public function testDifferenceEmptyAdoptsStateAndStreamsNothing(): void
    {
        $captured = [];
        $calls = [];
        $poller = new UpdatePollerService($this->capturingSink($captured));
        $scope = $this->fakeScope(
            [['_' => 'updates.differenceEmpty', 'pts' => 7, 'date' => 30, 'seq' => 2, 'qts' => 0]],
            $calls,
            fn () => $poller->stop()
        );

        $poller->pollUser($scope);

        $this->assertSame([], $captured);
        $this->assertSame(['pts' => 7, 'date' => 30, 'qts' => 0, 'seq' => 2], $poller->getSequenceState());
    }

    // ------------------------------------------------------------------
    // updates.differenceSlice: adopt intermediate_state, keep fetching,
    // never refetch the same window (the old infinite-loop bug)
    // ------------------------------------------------------------------

    public function testSliceAdoptsIntermediateStateAndContinuesFromAdvancedPts(): void
    {
        $captured = [];
        $calls = [];
        $poller = new UpdatePollerService($this->capturingSink($captured));
        $scope = $this->fakeScope([
            [
                '_' => 'updates.differenceSlice',
                'intermediate_state' => ['_' => 'updates.state', 'pts' => 5, 'date' => 40, 'qts' => 0, 'seq' => 0],
                'new_messages' => [['id' => 1, 'message' => 'slice-1']],
                'other_updates' => [],
                'new_encrypted_messages' => [],
            ],
            [
                '_' => 'updates.difference',
                'state' => ['_' => 'updates.state', 'pts' => 10, 'date' => 50, 'qts' => 2, 'seq' => 3],
                'new_messages' => [['id' => 2, 'message' => 'final']],
                'other_updates' => [],
                'new_encrypted_messages' => [],
            ],
        ], $calls, fn () => $poller->stop());

        $poller->pollUser($scope);

        // Both windows fetched, second request continues from the
        // intermediate pts (5), not the original window (0).
        $this->assertCount(2, $calls);
        $this->assertSame(0, $calls[0]['params']['pts']);
        $this->assertSame(5, $calls[1]['params']['pts']);

        // Strictly forward progress across every fetch.
        $requestedPts = array_map(fn (array $c): int => $c['params']['pts'], $calls);
        for ($i = 1; $i < count($requestedPts); $i++) {
            $this->assertGreaterThan($requestedPts[$i - 1], $requestedPts[$i]);
        }

        $this->assertCount(2, $captured);
        $this->assertSame('slice-1', $captured[0]['update']['message']['message']);
        $this->assertSame('final', $captured[1]['update']['message']['message']);
        $this->assertSame(['pts' => 10, 'date' => 50, 'qts' => 2, 'seq' => 3], $poller->getSequenceState());
    }

    public function testSliceChainEmitsGapThenResyncedEvents(): void
    {
        $gaps = [];
        $resyncs = [];
        $captured = [];
        $calls = [];

        $result = $this->withEventDispatcher(function (EventsDispatcher $dispatcher) use (&$gaps, &$resyncs, &$captured, &$calls): array {
            $this->listenForGapAndResync($dispatcher, $gaps, $resyncs);

            $poller = new UpdatePollerService($this->capturingSink($captured));
            $scope = $this->fakeScope([
                [
                    '_' => 'updates.differenceSlice',
                    'intermediate_state' => ['_' => 'updates.state', 'pts' => 5, 'date' => 40, 'qts' => 1, 'seq' => 0],
                    'new_messages' => [],
                    'other_updates' => [],
                    'new_encrypted_messages' => [],
                ],
                [
                    '_' => 'updates.difference',
                    'state' => ['_' => 'updates.state', 'pts' => 9, 'date' => 60, 'qts' => 1, 'seq' => 1],
                    'new_messages' => [],
                    'other_updates' => [],
                    'new_encrypted_messages' => [],
                ],
            ], $calls, fn () => $poller->stop());

            $poller->pollUser($scope, pts: 3, date: 10, qts: 0);

            return $poller->getSequenceState() ?? [];
        });

        $this->assertSame(['pts' => 9, 'date' => 60, 'qts' => 1, 'seq' => 1], $result);

        $this->assertCount(1, $gaps);
        $this->assertSame('slice', $gaps[0]['kind']);
        $this->assertSame(3, $gaps[0]['context']['from_pts']);
        $this->assertSame(5, $gaps[0]['context']['to_pts']);
        $this->assertSame(self::USER_ID, $gaps[0]['context']['account_id']);

        $this->assertCount(1, $resyncs);
        $this->assertSame(['pts' => 9, 'date' => 60, 'qts' => 1, 'seq' => 1], $resyncs[0]['state']);
        $this->assertSame(self::USER_ID, $resyncs[0]['accountId']);
    }

    public function testSliceWithoutProgressFlagsHoleAndStillAdvancesWindow(): void
    {
        $gaps = [];
        $resyncs = [];
        $captured = [];
        $calls = [];

        $requestedPts = $this->withEventDispatcher(function (EventsDispatcher $dispatcher) use (&$gaps, &$resyncs, &$captured, &$calls): array {
            $this->listenForGapAndResync($dispatcher, $gaps, $resyncs);

            $poller = new UpdatePollerService($this->capturingSink($captured));
            $noProgress = [
                '_' => 'updates.differenceSlice',
                'intermediate_state' => ['_' => 'updates.state', 'pts' => 0, 'date' => 1, 'qts' => 0, 'seq' => 0],
                'new_messages' => [],
                'other_updates' => [],
                'new_encrypted_messages' => [],
            ];
            $scope = $this->fakeScope([$noProgress, $noProgress, $noProgress], $calls, fn () => $poller->stop());

            $poller->pollUser($scope);

            return array_map(fn (array $c): int => $c['params']['pts'], $calls);
        });

        // The old bug refetched pts=0 forever; the hole guard must force
        // the requested window strictly forward on every no-progress slice.
        $this->assertSame([0, 1, 2], $requestedPts);
        $this->assertCount(3, $gaps);
        foreach ($gaps as $gap) {
            $this->assertSame('hole', $gap['kind']);
        }
    }

    // ------------------------------------------------------------------
    // updates.differenceTooLong: reset pts to server's, emit events
    // ------------------------------------------------------------------

    public function testTooLongResetsPtsToServersAndEmitsEvents(): void
    {
        $gaps = [];
        $resyncs = [];
        $captured = [];
        $calls = [];

        $finalState = $this->withEventDispatcher(function (EventsDispatcher $dispatcher) use (&$gaps, &$resyncs, &$captured, &$calls): array {
            $this->listenForGapAndResync($dispatcher, $gaps, $resyncs);

            $poller = new UpdatePollerService($this->capturingSink($captured));
            // Local state ahead of the server's view: tooLong must hard-reset,
            // not clamp to the stale local pts.
            $poller->setSequenceState(['pts' => 500, 'date' => 9000, 'qts' => 9, 'seq' => 40]);
            $scope = $this->fakeScope(
                [['_' => 'updates.differenceTooLong', 'pts' => 99, 'timeout' => 10]],
                $calls,
                fn () => $poller->stop()
            );

            $poller->pollUser($scope);

            return $poller->getSequenceState() ?? [];
        });

        $this->assertSame(99, $finalState['pts']);
        $this->assertSame(9000, $finalState['date']);
        $this->assertSame(9, $finalState['qts']);
        $this->assertSame(40, $finalState['seq']);

        $this->assertCount(1, $gaps);
        $this->assertSame('too_long', $gaps[0]['kind']);
        $this->assertSame(500, $gaps[0]['context']['local_pts']);
        $this->assertSame(99, $gaps[0]['context']['server_pts']);
        $this->assertSame(10, $gaps[0]['context']['timeout']);

        $this->assertCount(1, $resyncs);
        $this->assertSame(99, $resyncs[0]['state']['pts']);
    }

    public function testTooLongContinuesPollingFromResetPts(): void
    {
        $captured = [];
        $calls = [];
        $poller = new UpdatePollerService($this->capturingSink($captured));
        $poller->setSequenceState(['pts' => 500, 'date' => 9000, 'qts' => 9, 'seq' => 40]);
        $scope = $this->fakeScope([
            ['_' => 'updates.differenceTooLong', 'pts' => 99, 'timeout' => 10],
            ['_' => 'updates.differenceEmpty', 'pts' => 99, 'date' => 9000, 'seq' => 40, 'qts' => 9],
        ], $calls, fn () => $poller->stop());

        $poller->pollUser($scope);

        $this->assertCount(2, $calls);
        $this->assertSame(500, $calls[0]['params']['pts']);
        $this->assertSame(99, $calls[1]['params']['pts']);
    }

    // ------------------------------------------------------------------
    // Channel context: passthrough + channel pts exposure
    // ------------------------------------------------------------------

    public function testChannelUpdatesKeepChannelContextAndExposeChannelPts(): void
    {
        $captured = [];
        $calls = [];
        $poller = new UpdatePollerService($this->capturingSink($captured));
        $scope = $this->fakeScope([
            [
                '_' => 'updates.difference',
                'state' => ['_' => 'updates.state', 'pts' => 300, 'date' => 70, 'qts' => 0, 'seq' => 5],
                'new_messages' => [
                    ['id' => 11, 'message' => 'chan-post', 'peer_id' => ['_' => 'peerChannel', 'channel_id' => 42]],
                ],
                'other_updates' => [
                    [
                        '_' => 'updateNewChannelMessage',
                        'pts' => 101,
                        'pts_count' => 1,
                        'message' => ['id' => 12, 'peer_id' => ['_' => 'peerChannel', 'channel_id' => 42]],
                    ],
                    ['_' => 'updateDeleteChannelMessages', 'channel_id' => 43, 'messages' => [1], 'pts' => 8, 'pts_count' => 1],
                ],
                'new_encrypted_messages' => [],
            ],
        ], $calls, fn () => $poller->stop());

        $poller->pollUser($scope);

        // Raw passthrough: channel_id context preserved on every update.
        $this->assertCount(3, $captured);
        $this->assertSame('updateNewChannelMessage', $captured[0]['update']['_']);
        $this->assertSame(42, $captured[0]['update']['message']['peer_id']['channel_id']);

        $this->assertSame('updateDeleteChannelMessages', $captured[1]['update']['_']);
        $this->assertSame(43, $captured[1]['update']['channel_id']);

        // Channel message from new_messages wrapped with channel context.
        $this->assertSame('updateNewChannelMessage', $captured[2]['update']['_']);
        $this->assertSame(42, $captured[2]['update']['message']['peer_id']['channel_id']);

        $this->assertSame([42 => 101, 43 => 8], $poller->getChannelPts());
    }

    // ------------------------------------------------------------------
    // Sink backpressure contract
    // ------------------------------------------------------------------

    public function testSinkRefusalIsRespectedAndDoesNotBreakTheLoop(): void
    {
        $captured = [];
        $sink = $this->capturingSink($captured, refuseFirst: 1);
        $poller = new UpdatePollerService($sink);

        // First update refused (false = not now), second accepted.
        $poller->processUpdate(['_' => 'updateNewMessage', 'message' => ['id' => 1]], '1');
        $poller->processUpdate(['_' => 'updateNewMessage', 'message' => ['id' => 2]], '1');

        $this->assertCount(1, $captured);
        $this->assertSame(2, $captured[0]['update']['message']['id']);
    }

    // ------------------------------------------------------------------
    // Unknown / defensive shapes
    // ------------------------------------------------------------------

    public function testUnrecognizedDifferenceShapeStopsCatchUpWithoutLosingState(): void
    {
        $captured = [];
        $calls = [];
        $poller = new UpdatePollerService($this->capturingSink($captured));
        $poller->setSequenceState(['pts' => 33, 'date' => 800, 'qts' => 0, 'seq' => 1]);
        $scope = $this->fakeScope([['_' => 'updates.differenceSomethingNew']], $calls, null);

        try {
            $poller->pollUser($scope);
            $this->fail('pollUser should surface unrecognized difference shapes');
        } catch (Throwable $e) {
            // expected: fail fast on unknown constructor, do not silently loop
        }

        $this->assertSame(['pts' => 33, 'date' => 800, 'qts' => 0, 'seq' => 1], $poller->getSequenceState());
        $this->assertSame([], $captured);
    }
}
