<!-- @generated -->

# sendDocument

[Docs](https://core.telegram.org/bots/api#senddocument)

Use this method to send general files. On success, the sent Message is returned. Bots can currently send files of any type of up to 50 MB in size, this limit may be changed in the future.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| business_connection_id | string |  | Unique identifier of the business connection on behalf of which the message will be sent |
| chat_id | int | * | Unique identifier for the target chat or username of the target bot, supergroup or channel in the format @username |
| message_thread_id | int |  | Unique identifier for the target message thread (topic) of a forum; for forum supergroups and private chats of bots with forum topic mode enabled only |
| direct_messages_topic_id | int |  | Identifier of the direct messages topic to which the message will be sent; required if the message is sent to a direct messages chat |
| ephemeral_message_parameters | string |  | A JSON-serialized object containing the parameters of the ephemeral message to send |
| document | string | * | File to send. Pass a file_id as String to send a file that exists on the Telegram servers (recommended), pass an HTTP URL as a String for Telegram to get a file from the Internet, or upload a new one using multipart/form-data. More information on Sending Files: https://core.telegram.org/bots/api#sending-files |
| thumbnail | string |  | Thumbnail of the file sent; can be ignored if thumbnail generation for the file is supported server-side. The thumbnail should be in JPEG format and less than 200 kB in size. A thumbnail's width and height should not exceed 320. Ignored if the file is not uploaded using multipart/form-data. Thumbnails can't be reused and can be only uploaded as a new file, so you can pass "attach://<file_attach_name>" if the thumbnail was uploaded using multipart/form-data under <file_attach_name>. More information on Sending Files: https://core.telegram.org/bots/api#sending-files |
| caption | string |  | Document caption (may also be used when resending documents by file_id), 0-1024 characters after entities parsing |
| parse_mode | string |  | Mode for parsing entities in the document caption. See formatting options for more details. |
| caption_entities | array |  | A JSON-serialized list of special entities that appear in the caption, which can be specified instead of parse_mode |
| disable_content_type_detection | bool |  | Disables automatic server-side content type detection for files uploaded using multipart/form-data |
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
$request = Methods::bots()->sendDocument()
    ->chatId(123)
    ->document('text')
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(apiId: …, apiHash: …)
$result = $client->dispatch($request);
```
