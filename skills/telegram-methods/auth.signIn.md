<!-- @generated -->

# auth.signIn

[Docs](https://core.telegram.org/method/auth.signIn)

Signs in a user with a validated phone number.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| phone_number | string | * | Phone number in the international format |
| phone_code_hash | string | * | SMS-message ID, obtained from [auth.sendCode](../methods/auth.sendCode.md) |
| phone_code | string |  | Valid numerical code from the SMS-message |
| email_verification | EmailVerification |  | Email verification code or token |

## Returns

auth.Authorization

## Errors

- `AUTH_RESTART` — Restart the authorization process.
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `PHONE_CODE_EMPTY` — phone_code is missing.
- `PHONE_CODE_EXPIRED` — The phone code you provided has expired.
- `PHONE_CODE_INVALID` — The provided phone code is invalid.
- `PHONE_NUMBER_INVALID` — The phone number is invalid.
- `PHONE_NUMBER_UNOCCUPIED` — The phone number is not yet being used.
- `SIGN_IN_FAILED` — Failure while signing in.
- `UPDATE_APP_TO_LOGIN` — Please update your client to login.

## Usage

```php
$request = Methods::auth()->signIn()
    ->phoneNumber('text')
    ->phoneCodeHash('text')
    ->toRequest();

$result = TeleprotoClient::dispatch($request);
```
