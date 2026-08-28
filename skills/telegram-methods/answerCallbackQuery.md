<!-- @generated -->

# answerCallbackQuery

[Docs](https://core.telegram.org/bots/api#answercallbackquery)

Use this method to send answers to callback queries sent from inline keyboards. The answer will be displayed to the user as a notification at the top of the chat screen or as an alert. On success, True is returned.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| callback_query_id | string | * | Unique identifier for the query to be answered |
| text | string |  | Text of the notification. If not specified, nothing will be shown to the user, 0-200 characters. |
| show_alert | bool |  | If True, an alert will be shown by the client instead of a notification at the top of the chat screen. Defaults to False. |
| url | string |  | URL that will be opened by the user's client. If you have created a Game and accepted the conditions via @BotFather, specify the URL that opens your game - note that this will only work if the query comes from a callback_game button. Otherwise, you may use links like t.me/your_bot?start=XXXX that open your bot with a parameter. |
| cache_time | int |  | The maximum amount of time in seconds that the result of the callback query may be cached client-side. Defaults to 0. |

## Returns

Boolean

## Usage

```php
$request = Methods::bots()->answerCallbackQuery()
    ->callbackQueryId('text')
    ->toRequest();

$result = TeleprotoClient::dispatch($request);
```
