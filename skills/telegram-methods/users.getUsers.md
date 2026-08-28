<!-- @generated -->

# users.getUsers

[Docs](https://core.telegram.org/method/users.getUsers)

Returns basic user info according to their identifiers.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| id | Vector<InputUser> | * | List of user identifiers |

## Returns

Vector<User>

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `CHANNEL_INVALID` — The provided channel is invalid.
- `CHANNEL_MONOFORUM_UNSUPPORTED` — [Monoforums](https://core.telegram.org/api/channel#monoforums) do not support this feature.
- `CHANNEL_PRIVATE` — You haven't joined this channel/supergroup.
- `FROM_MESSAGE_BOT_DISABLED` — Bots can't use fromMessage min constructors.
- `MSG_ID_INVALID` — Invalid message ID provided.
- `PEER_ID_INVALID` — The provided peer id is invalid.
- `USER_BANNED_IN_CHANNEL` — You're banned from sending messages in supergroups/channels.

## Usage

```php
$request = Methods::users()->getUsers()
    ->id(['_' => '…'])
    ->toRequest();

$result = TeleprotoClient::dispatch($request);
```
