<!-- @generated -->

# sendMediaGroup

[Docs](https://core.telegram.org/bots/api#sendmediagroup)

Use this method to send a group of photos, live photos, videos, documents or audios as an album. Documents and audio files can be only grouped in an album with messages of the same type. On success, an Array of Message objects that were sent is returned.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| business_connection_id | string |  | Unique identifier of the business connection on behalf of which the message will be sent |
| chat_id | int | * | Unique identifier for the target chat or username of the target bot, supergroup or channel in the format @username |
| message_thread_id | int |  | Unique identifier for the target message thread (topic) of a forum; for forum supergroups and private chats of bots with forum topic mode enabled only |
| direct_messages_topic_id | int |  | Identifier of the direct messages topic to which the messages will be sent; required if the messages are sent to a direct messages chat |
| media | array | * | A JSON-serialized Array describing messages to be sent, must include 2-10 items |
| disable_notification | bool |  | Sends messages silently. Users will receive a notification with no sound. |
| protect_content | bool |  | Protects the contents of the sent messages from forwarding and saving |
| allow_paid_broadcast | bool |  | Pass True to allow up to 1000 messages per second, ignoring broadcasting limits for a fee of 0.1 Telegram Stars per message. The relevant Stars will be withdrawn from the bot's balance. |
| message_effect_id | string |  | Unique identifier of the message effect to be added to the message; for private chats only |
| reply_parameters | string |  | Description of the message to reply to |

## Returns

Array of Message

## Usage

```php
$request = Methods::bots()->sendMediaGroup()
    ->chatId(123)
    ->media(['_' => '…'])
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(defaultApiId: …, defaultApiHash: …)
$result = $client->dispatch($request);
```
