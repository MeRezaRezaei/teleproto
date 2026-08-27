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

    public function testInlineKeyboardAndInputMediaBuilders(): void
    {
        $keyboard = \MeRezaRezaei\Teleproto\Types\InlineKeyboard::make()
            ->row([
                \MeRezaRezaei\Teleproto\Types\InlineKeyboard::urlButton('Website', 'https://example.com'),
                \MeRezaRezaei\Teleproto\Types\InlineKeyboard::callbackButton('Click Me', 'btn_clicked'),
            ])
            ->row([
                \MeRezaRezaei\Teleproto\Types\InlineKeyboard::webAppButton('Open App', 'https://app.example.com'),
            ]);

        $arr = $keyboard->toArray();
        $this->assertCount(2, $arr['inline_keyboard']);
        $this->assertEquals('Website', $arr['inline_keyboard'][0][0]['text']);
        $this->assertEquals('https://example.com', $arr['inline_keyboard'][0][0]['url']);
        $this->assertEquals('Click Me', $arr['inline_keyboard'][0][1]['text']);
        $this->assertEquals('btn_clicked', $arr['inline_keyboard'][0][1]['callback_data']);

        $photo = \MeRezaRezaei\Teleproto\Types\InputMedia::photo('https://example.com/photo.jpg', 'Cool Photo');
        $this->assertEquals('photo', $photo['type']);
        $this->assertEquals('https://example.com/photo.jpg', $photo['media']);
        $this->assertEquals('Cool Photo', $photo['caption']);

        $video = \MeRezaRezaei\Teleproto\Types\InputMedia::video('https://example.com/video.mp4', 'Cool Video');
        $this->assertEquals('video', $video['type']);

        $replyKeyboard = \MeRezaRezaei\Teleproto\Types\ReplyKeyboard::make()
            ->row([\MeRezaRezaei\Teleproto\Types\ReplyKeyboard::requestContact('Send My Phone')])
            ->row(['Option 1', 'Option 2'])
            ->resize()
            ->oneTime();

        $replyArr = $replyKeyboard->toArray();
        $this->assertTrue($replyArr['resize_keyboard']);
        $this->assertTrue($replyArr['one_time_keyboard']);
        $this->assertCount(2, $replyArr['keyboard']);
        $this->assertTrue($replyArr['keyboard'][0][0]['request_contact']);

        $remove = \MeRezaRezaei\Teleproto\Types\ReplyKeyboard::remove(true);
        $this->assertTrue($remove['remove_keyboard']);
        $this->assertTrue($remove['selective']);
    }

    public function testBotClientTypedMethods(): void
    {
        $httpFactory = new \Illuminate\Http\Client\Factory();
        $httpFactory->fake([
            'https://api.telegram.org/bot12345/sendPhoto' => $httpFactory->response(['ok' => true, 'result' => ['photo' => []]], 200),
            'https://api.telegram.org/bot12345/answerCallbackQuery' => $httpFactory->response(['ok' => true, 'result' => true], 200),
            'https://api.telegram.org/bot12345/setWebhook' => $httpFactory->response(['ok' => true, 'result' => true], 200),
        ]);

        $bot = new \MeRezaRezaei\Teleproto\Services\BotClient('12345', http: $httpFactory);
        $resPhoto = $bot->sendPhoto('@channel', 'https://example.com/photo.jpg', 'Caption');
        $this->assertTrue($resPhoto['ok']);

        $resCb = $bot->answerCallbackQuery('query_123', 'Got it!', true);
        $this->assertTrue($resCb['ok']);

        $resWh = $bot->setWebhook('https://example.com/hook');
        $this->assertTrue($resWh['ok']);
    }

    public function testBotMtprotoScope(): void
    {
        $client = new TeleprotoClient(
            defaultApiId: 12345,
            defaultApiHash: 'hash_abc',
            defaultBotToken: '123456:BOT-TOKEN'
        );

        $botMtproto = $client->botMtproto(session: random_bytes(256));
        $this->assertInstanceOf(\MeRezaRezaei\Teleproto\Services\BotAccountScope::class, $botMtproto);
        $this->assertEquals('123456:BOT-TOKEN', $botMtproto->botToken);
        $this->assertEquals(12345, $botMtproto->mtproto->apiId);

        $loginRes = $botMtproto->login();
        $this->assertEquals('rpc_result', $loginRes['_']);
        $this->assertEquals('auth.importBotAuthorization', $loginRes['method']);
        $this->assertEquals('123456:BOT-TOKEN', $loginRes['params']['bot_auth_token']);

        // Send message over MTProto as Bot
        $msgRes = $botMtproto->sendMessage(peer: '@channel', text: 'Bot message over native MTProto!');
        $this->assertEquals('rpc_result', $msgRes['_']);
        $this->assertEquals('messages.sendMessage', $msgRes['method']);
    }

    public function testDefaultUserAndBotSessionFallback(): void
    {
        $dummyUserSession = (new SessionData(dcId: 2, authKey: random_bytes(256), userId: 112233))->exportString();
        $dummyBotSession = (new SessionData(dcId: 4, authKey: random_bytes(256), userId: 445566))->exportString();

        $client = new TeleprotoClient(
            defaultApiId: 9999,
            defaultApiHash: 'hash9999',
            defaultBotToken: '123456:BOT-DEFAULT',
            defaultUserSession: $dummyUserSession,
            defaultBotSession: $dummyBotSession
        );

        // Calling user() without session should automatically hydrate from defaultUserSession
        $userScope = $client->user();
        $this->assertEquals(112233, $userScope->session->userId);
        $this->assertEquals(2, $userScope->session->dcId);

        // Calling botMtproto() without session should automatically hydrate from defaultBotSession
        $botScope = $client->botMtproto();
        $this->assertEquals(445566, $botScope->session->userId);
        $this->assertEquals(4, $botScope->session->dcId);
    }

    public function testTerminalQrRendering(): void
    {
        $url = 'tg://login?token=dGVzdF9sb2dpbl90b2tlbl8xMjM0NTY3ODkw';
        $qrString = \MeRezaRezaei\Teleproto\Support\TerminalQr::render($url);

        $this->assertNotEmpty($qrString);
        $this->assertStringContainsString('█', $qrString);
    }

    public function testUserSpecificMtprotoMethods(): void
    {
        $client = new TeleprotoClient(defaultApiId: 7777, defaultApiHash: 'hash7777');
        $user = $client->user(12345, random_bytes(256));

        // Reaction
        $resReact = $user->sendReaction('@channel', 100, '🔥');
        $this->assertEquals('messages.sendReaction', $resReact['method']);
        $this->assertEquals('reactionEmoji', $resReact['params']['reaction'][0]['_']);
        $this->assertEquals('🔥', $resReact['params']['reaction'][0]['emoticon']);

        // Pin message
        $resPin = $user->pinMessage('@channel', 100, silent: true);
        $this->assertEquals('messages.updatePinnedMessage', $resPin['method']);
        $this->assertTrue($resPin['params']['silent']);

        // Create channel
        $resChan = $user->createChannel('My Channel', 'About channel', megagroup: false);
        $this->assertEquals('channels.createChannel', $resChan['method']);
        $this->assertTrue($resChan['params']['broadcast']);

        // Import contacts
        $contact = \MeRezaRezaei\Teleproto\Types\InputContact::phone('+1234567890', 'John', 'Doe');
        $resImport = $user->importContacts([$contact]);
        $this->assertEquals('contacts.importContacts', $resImport['method']);
        $this->assertEquals('+1234567890', $resImport['params']['contacts'][0]['phone']);

        // Update profile
        $resProfile = $user->updateProfile(firstName: 'Alice', about: 'Developer');
        $this->assertEquals('account.updateProfile', $resProfile['method']);
        $this->assertEquals('Alice', $resProfile['params']['first_name']);
        $this->assertEquals('Developer', $resProfile['params']['about']);
    }

    public function testTelegramUpdateReceivedEvent(): void
    {
        $rawUpdate = [
            'update_id' => 889900,
            'message' => [
                'message_id' => 1234,
                'from' => ['id' => 555, 'first_name' => 'Bob'],
                'text' => '/start',
            ],
        ];

        $event = new \MeRezaRezaei\Teleproto\Events\TelegramUpdateReceived($rawUpdate, '123456:BOT');
        $this->assertEquals(889900, $event->getUpdateId());
        $this->assertEquals('/start', $event->getMessage()['text']);
        $this->assertEquals(1234, $event->getMessage()['message_id']);
        $this->assertNull($event->getCallbackQuery());
    }

    public function testTelegramWebhookControllerResponse(): void
    {
        $controller = new \MeRezaRezaei\Teleproto\Http\Controllers\TelegramWebhookController();
        $req = \Illuminate\Http\Request::create('/telegram/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['update_id' => 101, 'message' => ['text' => 'Hi']]));

        $response = $controller($req);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['ok' => true], json_decode($response->getContent(), true));
    }

    public function testUpdatePollerServiceWithCustomSinkAndFilter(): void
    {
        $capturedUpdates = [];

        $customSink = new class($capturedUpdates) implements \MeRezaRezaei\Teleproto\Contracts\UpdateSinkInterface {
            public function __construct(public array &$storage) {}
            public function handle(array $update, ?string $source = null): void
            {
                $this->storage[] = ['source' => $source, 'update' => $update];
            }
        };

        $poller = new \MeRezaRezaei\Teleproto\Services\UpdatePollerService($customSink);

        // Filter out any update without 'message'
        $poller->filter(fn($upd) => isset($upd['message']));

        // Process message update (should pass)
        $poller->processUpdate(['update_id' => 1, 'message' => ['text' => 'Hello']], 'bot_1');
        // Process callback update (should be filtered out)
        $poller->processUpdate(['update_id' => 2, 'callback_query' => ['data' => '123']], 'bot_1');

        $this->assertCount(1, $capturedUpdates);
        $this->assertEquals('bot_1', $capturedUpdates[0]['source']);
        $this->assertEquals('Hello', $capturedUpdates[0]['update']['message']['text']);
    }

    public function testTeleprotoAuthServiceMethods(): void
    {
        $authService = new \MeRezaRezaei\Teleproto\Services\TeleprotoAuthService();

        // 1. Phone code flow
        $phoneRes = $authService->sendPhoneCode('+1234567890', 12345, 'hash123');
        $this->assertNotEmpty($phoneRes['phone_code_hash']);
        $this->assertInstanceOf(\MeRezaRezaei\Teleproto\MTProto\SessionData::class, $phoneRes['session']);

        $signInRes = $authService->signInWithCode($phoneRes['user'], '+1234567890', $phoneRes['phone_code_hash'], '12345');
        $this->assertEquals('rpc_result', $signInRes['_']);
        $this->assertEquals('auth.signIn', $signInRes['method']);

        // 2. QR code export
        $qrRes = $authService->exportQrLoginToken(12345, 'hash123');
        $this->assertStringStartsWith('tg://login?token=', $qrRes['url']);
        $this->assertNotEmpty($qrRes['token']);

        // 3. Bot MTProto authorization
        $botAuthRes = $authService->loginBot('123456:BOT-TOKEN', 12345, 'hash123');
        $this->assertEquals('rpc_result', $botAuthRes['raw']['_']);
        $this->assertEquals('auth.importBotAuthorization', $botAuthRes['raw']['method']);
    }
}
