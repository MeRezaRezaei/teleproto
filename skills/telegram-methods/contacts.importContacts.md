<!-- @generated -->

# contacts.importContacts

[Docs](https://core.telegram.org/method/contacts.importContacts)

Imports contacts: saves a full list on the server, adds already registered contacts to the contact list, returns added contacts and their info.

Use [contacts.addContact](../methods/contacts.addContact.md) to add Telegram contacts without actually using their phone number.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| contacts | Vector<InputContact> | * | List of contacts to import |

## Returns

contacts.ImportedContacts

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.

## Usage

```php
$request = Methods::contacts()->importContacts()
    ->contacts(['_' => '…'])
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(defaultApiId: …, defaultApiHash: …)
$result = $client->dispatch($request);
```
