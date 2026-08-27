# Bot API Client Guide

The Bot API client provides simple, clean methods to interact with Telegram Bots using either your default `.env` token or custom tokens at runtime.

---

## 1. Basic Usage

```php
use MeRezaRezaei\Teleproto\Facades\Teleproto;

// Send message via default bot (TELEGRAM_BOT_TOKEN from .env)
$bot = Teleproto::bot();
$bot->sendMessage(chatId: '@mychannel', text: 'Hello Channel Subscribers!');

// Send message via dynamic bot token
$customBot = Teleproto::bot('123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11');
$customBot->sendMessage(chatId: 987654321, text: 'Direct notification');
```

---

## 2. Interactive Keyboards & Options

```php
$bot->sendMessage(
    chatId: $userId,
    text: 'Please select an option below:',
    options: [
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '🌐 Visit Website', 'url' => 'https://example.com'],
                    ['text' => '📱 Open Mini App', 'web_app' => ['url' => 'https://app.example.com']],
                ]
            ]
        ])
    ]
);
```

---

## 3. Generic Bot API Methods

You can invoke any method from the Telegram Bot API:

```php
// 1. Get bot info
$me = $bot->call('getMe');

// 2. Set Webhook URL
$bot->call('setWebhook', [
    'url' => 'https://yourdomain.com/api/telegram/webhook',
    'allowed_updates' => json_encode(['message', 'callback_query'])
]);

// 3. Answer Callback Query
$bot->call('answerCallbackQuery', [
    'callback_query_id' => $callbackQueryId,
    'text'              => 'Action completed!',
    'show_alert'        => false
]);
```

---

## 4. Handling Inbound Updates (Webhooks & Long Polling)

Teleproto provides a zero-boilerplate way to receive and process updates:

### Option A: Webhook in `routes/api.php`
```php
use Illuminate\Support\Facades\Route;

// 1. Registers POST /api/telegram/webhook with secret token security
Route::telegramWebhook('telegram/webhook');
```

### Option B: Local Polling (No HTTPS/ngrok required)
```bash
php artisan teleproto:poll
```

### Listening to Updates Anywhere in Laravel:
```php
use Illuminate\Support\Facades\Event;
use MeRezaRezaei\Teleproto\Events\TelegramUpdateReceived;
use MeRezaRezaei\Teleproto\Facades\TP;

Event::listen(TelegramUpdateReceived::class, function (TelegramUpdateReceived $event) {
    $msg = $event->getMessage();
    if ($msg && ($msg['text'] ?? '') === '/start') {
        TP::bot($event->botToken)->sendMessage($msg['chat']['id'], 'Hello! Bot is live on Teleproto.');
    }
});
```

---

## 5. Native High-Speed MTProto Bot Mode (`botMtproto`)

You can also run your bots directly over Telegram's native binary **MTProto 2.0 TCP sockets** using `auth.importBotAuthorization`, unlocking raw MTProto RPC calls, large file uploads (up to 4GB), and zero HTTP polling latency:

```php
use MeRezaRezaei\Teleproto\Facades\TP;
use MeRezaRezaei\Teleproto\Types\InputPeer;

$botMtproto = TP::botMtproto('123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11');
$botMtproto->login();

// Send message via native MTProto 2.0
$botMtproto->sendMessage(peer: '@mychannel', text: 'Fast Bot broadcast over MTProto binary socket!');
```
