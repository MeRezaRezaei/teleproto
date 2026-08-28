<!-- @generated -->

# messages.readHistory

[Docs](https://core.telegram.org/method/messages.readHistory)

Marks message history as read.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| peer | InputPeer | * | Target user or group |
| max_id | int | * | If a positive value is passed, only messages with identifiers less or equal than the given one will be read |

## Returns

messages.AffectedMessages

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BUSINESS_CONNECTION_INVALID` — The `connection_id` passed to the wrapping [invokeWithBusinessConnection](https://core.telegram.org/api/business) call is invalid.
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `CHANNEL_PRIVATE` — You haven't joined this channel/supergroup.
- `CHAT_ID_INVALID` — The provided chat id is invalid.
- `MSG_ID_INVALID` — Invalid message ID provided.
- `PEER_ID_INVALID` — The provided peer id is invalid.

## Usage

```php
$request = Methods::messages()->readHistory()
    ->peer(['_' => '…'])
    ->maxId(123)
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(defaultApiId: …, defaultApiHash: …)
$result = $client->dispatch($request);
```
