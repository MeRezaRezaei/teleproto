<!-- @generated -->

# messages.deleteMessages

[Docs](https://core.telegram.org/method/messages.deleteMessages)

Deletes messages by their identifiers.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| revoke | true |  | Whether to delete messages for all participants of the chat |
| id | Vector<int> | * | Message ID list |

## Returns

messages.AffectedMessages

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BOT_ACCESS_FORBIDDEN` — The specified method *can* be used over a [business connection](https://core.telegram.org/api/bots/connected-business-bots) for some operations, but the specified query attempted an operation that is not allowed over a business connection.
- `BUSINESS_CONNECTION_INVALID` — The `connection_id` passed to the wrapping [invokeWithBusinessConnection](https://core.telegram.org/api/business) call is invalid.
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `MESSAGE_DELETE_FORBIDDEN` — You can't delete one of the messages you tried to delete, most likely because it is a service message.
- `MESSAGE_ID_INVALID` — The provided message id is invalid.
- `SELF_DELETE_RESTRICTED` — Business bots can't delete messages just for the user, `revoke` **must** be set.

## Usage

```php
$request = Methods::messages()->deleteMessages()
    ->id(['_' => '…'])
    ->toRequest();

$result = TeleprotoClient::dispatch($request);
```
