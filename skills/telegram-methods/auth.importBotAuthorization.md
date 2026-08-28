<!-- @generated -->

# auth.importBotAuthorization

[Docs](https://core.telegram.org/method/auth.importBotAuthorization)

Login as a bot

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| flags | int | * |  |
| api_id | int | * | Application identifier (see. [App configuration](https://core.telegram.org/myapp)) |
| api_hash | string | * | Application identifier hash (see. [App configuration](https://core.telegram.org/myapp)) |
| bot_auth_token | string | * | Bot token (see [bots](https://core.telegram.org/bots)) |

## Returns

auth.Authorization

## Errors

- `ACCESS_TOKEN_EXPIRED` — Access token expired.
- `ACCESS_TOKEN_INVALID` — Access token invalid.
- `API_ID_INVALID` — API ID invalid.
- `API_ID_PUBLISHED_FLOOD` — This API id was published somewhere, you can't use it now.
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.

## Usage

```php
$request = Methods::auth()->importBotAuthorization()
    ->flags(123)
    ->apiId(123)
    ->apiHash('text')
    ->toRequest();

$result = TeleprotoClient::dispatch($request);
```
