<!-- @generated -->

# auth.checkPassword

[Docs](https://core.telegram.org/method/auth.checkPassword)

Try logging to an account protected by a [2FA password](https://core.telegram.org/api/srp).

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| password | InputCheckPasswordSRP | * | The account's password (see [SRP](https://core.telegram.org/api/srp)) |

## Returns

auth.Authorization

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `AUTH_KEY_UNSYNCHRONIZED` — Internal error, please repeat the method call.
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `PASSWORD_HASH_INVALID` — The provided password hash is invalid.
- `SRP_ID_INVALID` — Invalid SRP ID provided.
- `SRP_PASSWORD_CHANGED` — Password has changed.

## Usage

```php
$request = Methods::auth()->checkPassword()
    ->password(['_' => '…'])
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(apiId: …, apiHash: …)
$result = $client->dispatch($request);
```
