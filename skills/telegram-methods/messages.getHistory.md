<!-- @generated -->

# messages.getHistory

[Docs](https://core.telegram.org/method/messages.getHistory)

Returns the conversation history with one interlocutor / within a chat

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| peer | InputPeer | * | Target peer |
| offset_id | int | * | Only return messages starting from the specified message ID |
| offset_date | int | * | Only return messages sent before the specified date |
| add_offset | int | * | Number of list elements to be skipped, negative values are also accepted. |
| limit | int | * | Number of results to return |
| max_id | int | * | If a positive value was transferred, the method will return only messages with IDs less than **max\_id** |
| min_id | int | * | If a positive value was transferred, the method will return only messages with IDs more than **min\_id** |
| hash | long | * | [Result hash](https://core.telegram.org/api/offsets) |

## Returns

messages.Messages

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `CHANNEL_INVALID` — The provided channel is invalid.
- `CHANNEL_PRIVATE` — You haven't joined this channel/supergroup.
- `CHAT_ID_INVALID` — The provided chat id is invalid.
- `CHAT_NOT_MODIFIED` — No changes were made to chat information because the new information you passed is identical to the current information.
- `FROZEN_PARTICIPANT_MISSING` — The current account is [frozen](https://core.telegram.org/api/auth#frozen-accounts), and cannot access the specified peer.
- `MSG_ID_INVALID` — Invalid message ID provided.
- `PEER_ID_INVALID` — The provided peer id is invalid.
- `TAKEOUT_INVALID` — The specified takeout ID is invalid.

## Usage

```php
$request = Methods::messages()->getHistory()
    ->peer(['_' => '…'])
    ->offsetId(123)
    ->offsetDate(123)
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(defaultApiId: …, defaultApiHash: …)
$result = $client->dispatch($request);
```
