<!-- @generated -->

# editMessageText

[Docs](https://core.telegram.org/bots/api#editmessagetext)

Use this method to edit text, rich and game messages. On success, if the edited message is not an inline message, the edited Message is returned, otherwise True is returned. Note that business messages that were not sent by the bot and do not contain an inline keyboard can only be edited within 48 hours from the time they were sent.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| business_connection_id | string |  | Unique identifier of the business connection on behalf of which the message to be edited was sent |
| chat_id | int |  | Required if inline_message_id is not specified. Unique identifier for the target chat or username of the target bot, supergroup or channel in the format @username. |
| message_id | int |  | Required if inline_message_id is not specified. Identifier of the message to edit. |
| inline_message_id | string |  | Required if chat_id and message_id are not specified. Identifier of the inline message. |
| text | string |  | New text of the message, 1-4096 characters after entity parsing; required if rich_message isn't specified |
| parse_mode | string |  | Mode for parsing entities in the message text. See formatting options for more details. |
| entities | array |  | A JSON-serialized list of special entities that appear in message text, which can be specified instead of parse_mode |
| link_preview_options | string |  | Link preview generation options for the message |
| rich_message | string |  | New rich content of the message; required if text isn't specified. Direct upload of new files and explicit upload of files by a URL isn't supported when an inline message is edited. |
| reply_markup | string |  | A JSON-serialized object for an inline keyboard |

## Returns

Message|Boolean

## Usage

```php
$request = Methods::bots()->editMessageText()
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(apiId: …, apiHash: …)
$result = $client->dispatch($request);
```
