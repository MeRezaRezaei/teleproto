<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests;

use PHPUnit\Framework\TestCase;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Services\TeleprotoClient;
use MeRezaRezaei\Teleproto\Types\InputPeer;

class TeleprotoClientTest extends TestCase
{
    public function testSingleTenantUserWithConfigDefaults(): void
    {
        $client = new TeleprotoClient(
            defaultApiId: 12345,
            defaultApiHash: 'default_hash_123'
        );

        $userScope = $client->user(
            accountId: 987654321,
            session: random_bytes(256),
            dcId: 2
        );

        $result = $userScope->sendMessage(peer: '@durov', text: 'Hello Single Tenant!');

        $this->assertEquals('rpc_result', $result['_']);
        $this->assertEquals('messages.sendMessage', $result['method']);
        $this->assertEquals(12345, $userScope->mtproto->apiId);
        $this->assertEquals('default_hash_123', $userScope->mtproto->apiHash);
    }

    public function testInputPeerHelpers(): void
    {
        $userPeer = InputPeer::user(123456, 'access_hash_abc');
        $this->assertEquals('inputPeerUser', $userPeer['_']);
        $this->assertEquals(123456, $userPeer['user_id']);
        $this->assertEquals('access_hash_abc', $userPeer['access_hash']);

        $channelPeer = InputPeer::channel(789012, 'hash_def');
        $this->assertEquals('inputPeerChannel', $channelPeer['_']);
        $this->assertEquals(789012, $channelPeer['channel_id']);

        $chatPeer = InputPeer::chat(45678);
        $this->assertEquals('inputPeerChat', $chatPeer['_']);

        $selfPeer = InputPeer::self();
        $this->assertEquals('inputPeerSelf', $selfPeer['_']);
    }

    public function testUserCommonMethodsWithDocMappedSignatures(): void
    {
        $client = new TeleprotoClient(defaultApiId: 111, defaultApiHash: 'hash');
        $user = $client->user(12345, random_bytes(256));

        // getHistory
        $res = $user->getHistory(InputPeer::channel(999), limit: 20);
        $this->assertEquals('messages.getHistory', $res['method']);
        $this->assertEquals(20, $res['params']['limit']);

        // getDialogs
        $res = $user->getDialogs(limit: 30);
        $this->assertEquals('messages.getDialogs', $res['method']);
        $this->assertEquals(30, $res['params']['limit']);

        // getFullUser
        $res = $user->getFullUser(987654);
        $this->assertEquals('users.getFullUser', $res['method']);
        $this->assertEquals(987654, $res['params']['id']['user_id']);

        // deleteMessages
        $res = $user->deleteMessages([101, 102], revoke: true);
        $this->assertEquals('messages.deleteMessages', $res['method']);
        $this->assertTrue($res['params']['revoke']);
    }

    public function testUserCreationFromExportedSessionString(): void
    {
        $client = new TeleprotoClient(
            defaultApiId: 54321,
            defaultApiHash: 'test_hash_456'
        );

        $originalSession = new SessionData(
            dcId: 4,
            authKey: random_bytes(256),
            serverTimeDelta: -5,
            userId: 555666777
        );

        $sessionString = $originalSession->exportString();

        $userScope = $client->fromSession($sessionString);

        $this->assertEquals(555666777, $userScope->session->userId);
        $this->assertEquals(4, $userScope->session->dcId);
        $this->assertEquals($originalSession->authKey, $userScope->session->authKey);

        $res = $userScope->sendMessage('@channel', 'Hello from imported string');
        $this->assertEquals('rpc_result', $res['_']);
        $this->assertEquals(4, $res['dc_id']);
    }

    public function testBotAccountWithDynamicToken(): void
    {
        $client = new TeleprotoClient(defaultBotToken: 'default:bot_token');

        $defaultBot = $client->bot();
        $this->assertEquals('default:bot_token', $defaultBot->botToken);

        $customBot = $client->bot('999999:CUSTOM-BOT-TOKEN');
        $this->assertEquals('999999:CUSTOM-BOT-TOKEN', $customBot->botToken);

        $this->expectException(TelegramException::class);
        $this->expectExceptionCode(401);
        $customBot->sendMessage('@chat', 'Bot announcement');
    }

    public function testBotClientWithHttpFake(): void
    {
        $httpFactory = new \Illuminate\Http\Client\Factory();
        $httpFactory->fake([
            'https://api.telegram.org/bot12345/getMe' => $httpFactory->response([
                'ok' => true,
                'result' => [
                    'id' => 12345,
                    'is_bot' => true,
                    'first_name' => 'MockBot',
                    'username' => 'mock_bot',
                ]
            ], 200),
            'https://api.telegram.org/bot12345/sendMessage' => $httpFactory->response([
                'ok' => true,
                'result' => [
                    'message_id' => 777,
                    'text' => 'Hello Mock',
                ]
            ], 200)
        ]);

        $bot = new \MeRezaRezaei\Teleproto\Services\BotClient('12345', http: $httpFactory);
        $me = $bot->call('getMe');
        $this->assertTrue($me['ok']);
        $this->assertEquals('MockBot', $me['result']['first_name']);

        $sent = $bot->sendMessage('@test', 'Hello Mock');
        $this->assertTrue($sent['ok']);
        $this->assertEquals(777, $sent['result']['message_id']);
    }
}
