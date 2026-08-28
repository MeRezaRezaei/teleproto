<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use Illuminate\Http\Client\Factory as HttpFactory;
use MeRezaRezaei\Teleproto\Methods\Methods;
use MeRezaRezaei\Teleproto\Schema\MethodRegistry;
use MeRezaRezaei\Teleproto\Services\TeleprotoClient;
use PHPUnit\Framework\TestCase;

class GeneratedBuildersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        MethodRegistry::load();
        require_once dirname(__DIR__, 2) . '/src/Methods/Generated/Messages.php';
        require_once dirname(__DIR__, 2) . '/src/Methods/Generated/Bots.php';
    }

    public function testSendMessageBuilderProducesExactRequestArray(): void
    {
        $req = Methods::messages()->sendMessage()
            ->peer(['_' => 'inputPeerSelf'])
            ->message('hello')
            ->randomId(12345)
            ->toRequest();
        $this->assertSame('messages.sendMessage', $req['_']);
        $this->assertSame(['_' => 'inputPeerSelf'], $req['peer']);
        $this->assertSame('hello', $req['message']);
        $this->assertSame(12345, $req['random_id']); // snake_case on the wire
    }

    public function testBuilderValidatesRequiredParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('message');
        Methods::messages()->sendMessage()->peer(['_' => 'inputPeerSelf'])->toRequest();
    }

    public function testBotBuilderRequiredList(): void
    {
        $req = Methods::bots()->sendMessage()->chatId('@ch')->text('hi')->toRequest();
        $this->assertSame('@ch', $req['chat_id']);
        $this->assertSame('hi', $req['text']);
    }

    public function testDispatchRoutesBotHttpRequestThroughBotClientHttp(): void
    {
        $http = new HttpFactory();
        $http->fake([
            'https://api.telegram.org/bot12345/getMe' => $http->response([
                'ok' => true,
                'result' => ['id' => 12345, 'is_bot' => true, 'username' => 'mock_bot'],
            ], 200),
        ]);

        $client = new TeleprotoClient(defaultApiId: 1, defaultApiHash: 'h', defaultBotToken: '12345', http: $http);
        $res = $client->dispatch(Methods::bots()->getMe()->toRequest());

        $this->assertTrue($res['ok']);
        $this->assertSame('mock_bot', $res['result']['username']);
        $http->assertSent(
            static fn ($request) => str_ends_with($request->url(), '/getMe')
        );
    }

    public function testDispatchRoutesMtprotoRequestThroughUserScopeStub(): void
    {
        $client = new TeleprotoClient(defaultApiId: 1, defaultApiHash: 'h', defaultUserSession: 'offline-unit-auth-key');

        $res = $client->dispatch(
            Methods::messages()->sendMessage()
                ->peer(['_' => 'inputPeerSelf'])
                ->message('hello')
                ->randomId(42)
                ->toRequest()
        );

        // Offline stub scope (live:false without a container): the rpc_result
        // echo proves dispatch routed to the user() MTProto scope.
        $this->assertSame('rpc_result', $res['_']);
        $this->assertSame('messages.sendMessage', $res['method']);
        $this->assertSame('hello', $res['params']['message']);
        $this->assertArrayNotHasKey('_', $res['params']); // dispatch strips the name marker
    }

    public function testDispatchUnknownMethodThrowsRegistryExceptionWithName(): void
    {
        $client = new TeleprotoClient(defaultApiId: 1, defaultApiHash: 'h', defaultBotToken: 't');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('totally.bogusMethod');
        $client->dispatch(['_' => 'totally.bogusMethod']);
    }
}
