<!-- @generated -->

# contacts.search

[Docs](https://core.telegram.org/method/contacts.search)

Returns users found by username substring.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| broadcasts | true |  |  |
| bots | true |  |  |
| q | string | * | Target substring |
| limit | int | * | Maximum number of users to be returned |

## Returns

contacts.Found

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `QUERY_TOO_SHORT` — The query string is too short.
- `SEARCH_QUERY_EMPTY` — The search query is empty.

## Usage

```php
$request = Methods::contacts()->search()
    ->q('text')
    ->limit(123)
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(apiId: …, apiHash: …)
$result = $client->dispatch($request);
```
