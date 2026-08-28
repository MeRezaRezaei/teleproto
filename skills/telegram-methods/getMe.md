<!-- @generated -->

# getMe

[Docs](https://core.telegram.org/bots/api#getme)

A simple method for testing your bot's authentication token. Requires no parameters. Returns basic information about the bot in form of a User object.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |

## Returns

User

## Usage

```php
$request = Methods::bots()->getMe()
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(defaultApiId: …, defaultApiHash: …)
$result = $client->dispatch($request);
```
