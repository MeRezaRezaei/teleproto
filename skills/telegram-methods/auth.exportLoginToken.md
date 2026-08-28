<!-- @generated -->

# auth.exportLoginToken

[Docs](https://core.telegram.org/method/auth.exportLoginToken)

Generate a login token, for [login via QR code](https://core.telegram.org/api/qr-login).  
The generated login token should be encoded using base64url, then shown as a `tg://login?token=base64encodedtoken` [deep link »](https://core.telegram.org/api/links#qr-code-login-links) in the QR code.

For more info, see [login via QR code](https://core.telegram.org/api/qr-login).

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| api_id | int | * | Application identifier (see. [App configuration](https://core.telegram.org/myapp)) |
| api_hash | string | * | Application identifier hash (see. [App configuration](https://core.telegram.org/myapp)) |
| except_ids | Vector<long> | * | List of already logged-in user IDs, to prevent logging in twice with the same user |

## Returns

auth.LoginToken

## Errors

- `API_ID_INVALID` — API ID invalid.
- `API_ID_PUBLISHED_FLOOD` — This API id was published somewhere, you can't use it now.
- `AUTH_RESTART` — Restart the authorization process.
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.

## Usage

```php
$request = Methods::auth()->exportLoginToken()
    ->apiId(123)
    ->apiHash('text')
    ->exceptIds(['_' => '…'])
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(defaultApiId: …, defaultApiHash: …)
$result = $client->dispatch($request);
```
