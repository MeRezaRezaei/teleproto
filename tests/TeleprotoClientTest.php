<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests;

use PHPUnit\Framework\TestCase;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Services\TeleprotoClient;

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

        // Bind directly using exported string
        $userScope = $client->fromSession($sessionString);

        $this->assertEquals(555666777, $userScope->session->userId);
        $this->assertEquals(4, $userScope->session->dcId);
        $this->assertEquals($originalSession->authKey, $userScope->session->authKey);

        $res = $userScope->sendMessage('@channel', 'Hello from imported string');
        $this->assertEquals('rpc_result', $res['_']);
        $this->assertEquals(4, $res['dc_id']);
    }

    public function testMultiTenantUserWithRuntimeCredentials(): void
    {
        $client = new TeleprotoClient();

        // Custom account 1
        $user1 = $client->user(
            accountId: 111,
            session: random_bytes(256),
            dcId: 2,
            apiId: 77777,
            apiHash: 'custom_hash_account_1'
        );

        // Custom account 2 with different API ID and hash
        $user2 = $client->user(
            accountId: 222,
            session: random_bytes(256),
            dcId: 4,
            apiId: 88888,
            apiHash: 'custom_hash_account_2'
        );

        $this->assertEquals(77777, $user1->mtproto->apiId);
        $this->assertEquals('custom_hash_account_1', $user1->mtproto->apiHash);
        $this->assertEquals(2, $user1->session->dcId);

        $this->assertEquals(88888, $user2->mtproto->apiId);
        $this->assertEquals('custom_hash_account_2', $user2->mtproto->apiHash);
        $this->assertEquals(4, $user2->session->dcId);
    }

    public function testBotAccountWithDynamicToken(): void
    {
        $client = new TeleprotoClient(defaultBotToken: 'default:bot_token');

        // Bot with default token
        $defaultBot = $client->bot();
        $this->assertEquals('default:bot_token', $defaultBot->botToken);

        // Bot with dynamic token
        $customBot = $client->bot('999999:CUSTOM-BOT-TOKEN');
        $this->assertEquals('999999:CUSTOM-BOT-TOKEN', $customBot->botToken);

        // Calling with invalid dummy token throws TelegramException 401 Unauthorized
        $this->expectException(TelegramException::class);
        $this->expectExceptionCode(401);
        $customBot->sendMessage('@chat', 'Bot announcement');
    }

    public function testUserWithoutApiCredentialsThrowsException(): void
    {
        $client = new TeleprotoClient(); // No defaults

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Telegram API ID and API Hash are required');

        $client->user(accountId: 12345, session: random_bytes(256));
    }
}
