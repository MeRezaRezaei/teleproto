<!-- @generated -->

# messages.getDialogs

[Docs](https://core.telegram.org/method/messages.getDialogs)

Returns the current user dialog list.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| exclude_pinned | true |  | Exclude pinned dialogs |
| folder_id | int |  | [Peer folder ID, for more info click here](https://core.telegram.org/api/folders#peer-folders) |
| offset_date | int | * | [Offsets for pagination, for more info click here](https://core.telegram.org/api/offsets) |
| offset_id | int | * | [Offsets for pagination, for more info click here](https://core.telegram.org/api/offsets) (`top_message` ID used for pagination) |
| offset_peer | InputPeer | * | [Offset peer for pagination](https://core.telegram.org/api/offsets) |
| limit | int | * | Number of list elements to be returned |
| hash | long | * | [Hash used for caching, for more info click here](https://core.telegram.org/api/offsets#hash-generation) |

## Returns

messages.Dialogs

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `CHAT_NOT_MODIFIED` — No changes were made to chat information because the new information you passed is identical to the current information.
- `CHAT_WRITE_FORBIDDEN` — You can't write in this chat.
- `FOLDER_ID_INVALID` — Invalid folder ID.
- `OFFSET_PEER_ID_INVALID` — The provided offset peer is invalid.
- `PINNED_DIALOGS_TOO_MUCH` — Too many pinned dialogs.
- `TAKEOUT_INVALID` — The specified takeout ID is invalid.

## Usage

```php
$request = Methods::messages()->getDialogs()
    ->offsetDate(123)
    ->offsetId(123)
    ->offsetPeer(['_' => '…'])
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(apiId: …, apiHash: …)
$result = $client->dispatch($request);
```
