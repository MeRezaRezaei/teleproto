<!-- @generated -->

# help.getNearestDc

[Docs](https://core.telegram.org/method/help.getNearestDc)

Returns info on data center nearest to the user.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |

## Returns

NearestDc

## Errors

- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.

## Usage

```php
$request = Methods::help()->getNearestDc()
    ->toRequest();

$result = TeleprotoClient::dispatch($request);
```
