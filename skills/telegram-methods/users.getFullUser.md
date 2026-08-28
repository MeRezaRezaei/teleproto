<!-- @generated -->

# users.getFullUser

[Docs](https://core.telegram.org/method/users.getFullUser)

Returns extended user info by ID.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| id | InputUser | * | User ID |

## Returns

users.UserFull

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `CHANNEL_INVALID` — The provided channel is invalid.
- `CHANNEL_PRIVATE` — You haven't joined this channel/supergroup.
- `MSG_ID_INVALID` — Invalid message ID provided.
- `USERNAME_OCCUPIED` — The provided username is already occupied.
- `USER_ID_INVALID` — The provided user ID is invalid.

## Usage

```php
$request = Methods::users()->getFullUser()
    ->id(['_' => '…'])
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(defaultApiId: …, defaultApiHash: …)
$result = $client->dispatch($request);
```
