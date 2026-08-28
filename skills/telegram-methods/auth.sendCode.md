<!-- @generated -->

# auth.sendCode

[Docs](https://core.telegram.org/method/auth.sendCode)

Send the verification code for login

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| phone_number | string | * | Phone number in international format |
| api_id | int | * | Application identifier (see [App configuration](https://core.telegram.org/myapp)) |
| api_hash | string | * | Application secret hash (see [App configuration](https://core.telegram.org/myapp)) |
| settings | CodeSettings | * | Settings for the code type to send |

## Returns

auth.SentCode

## Errors

- `API_ID_INVALID` — API ID invalid.
- `API_ID_PUBLISHED_FLOOD` — This API id was published somewhere, you can't use it now.
- `AUTH_RESTART` — Restart the authorization process.
- `AUTH_RESTART_%d` — Internal error (debug info 30), please repeat the method call.
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `PHONE_NUMBER_APP_SIGNUP_FORBIDDEN` — You can't sign up using this app.
- `PHONE_NUMBER_BANNED` — The provided phone number is banned from telegram.
- `PHONE_NUMBER_FLOOD` — You asked for the code too many times.
- `PHONE_NUMBER_INVALID` — The phone number is invalid.
- `PHONE_PASSWORD_FLOOD` — You have tried logging in too many times.
- `PHONE_PASSWORD_PROTECTED` — This phone is password protected.
- `SMS_CODE_CREATE_FAILED` — An error occurred while creating the SMS code.
- `UPDATE_APP_TO_LOGIN` — Please update your client to login.

## Usage

```php
$request = Methods::auth()->sendCode()
    ->phoneNumber('text')
    ->apiId(123)
    ->apiHash('text')
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(defaultApiId: …, defaultApiHash: …)
$result = $client->dispatch($request);
```
