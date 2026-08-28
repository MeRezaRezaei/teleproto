<!-- @generated -->

# sendMessage

[Docs](https://core.telegram.org/bots/api#sendmessage)

Use this method to send text messages. On success, the sent Message is returned.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| business_connection_id | string |  | Unique identifier of the business connection on behalf of which the message will be sent |
| chat_id | int | * | Unique identifier for the target chat or username of the target bot, supergroup or channel in the format @username |
| message_thread_id | int |  | Unique identifier for the target message thread (topic) of a forum; for forum supergroups and private chats of bots with forum topic mode enabled only |
| direct_messages_topic_id | int |  | Identifier of the direct messages topic to which the message will be sent; required if the message is sent to a direct messages chat |
| ephemeral_message_parameters | string |  | A JSON-serialized object containing the parameters of the ephemeral message to send |
| text | string | * | Text of the message to be sent, 1-4096 characters after entities parsing |
| parse_mode | string |  | Mode for parsing entities in the message text. See formatting options for more details. |
| entities | array |  | A JSON-serialized list of special entities that appear in message text, which can be specified instead of parse_mode |
| link_preview_options | string |  | Link preview generation options for the message |
| disable_notification | bool |  | Sends the message silently. Users will receive a notification with no sound. |
| protect_content | bool |  | Protects the contents of the sent message from forwarding and saving |
| allow_paid_broadcast | bool |  | Pass True to allow up to 1000 messages per second, ignoring broadcasting limits for a fee of 0.1 Telegram Stars per message. The relevant Stars will be withdrawn from the bot's balance. |
| message_effect_id | string |  | Unique identifier of the message effect to be added to the message; for private chats only |
| suggested_post_parameters | string |  | A JSON-serialized object containing the parameters of the suggested post to send; for direct messages chats only. If the message is sent as a reply to another suggested post, then that suggested post is automatically declined. |
| reply_parameters | string |  | Description of the message to reply to |
| reply_markup | string |  | Additional interface options. A JSON-serialized object for an inline keyboard, custom reply keyboard, instructions to remove a reply keyboard or to force a reply from the user. |

## Returns

Message

## Usage

```php
$request = Methods::bots()->sendMessage()
    ->chatId(123)
    ->text('text')
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(apiId: …, apiHash: …)
$result = $client->dispatch($request);
```
