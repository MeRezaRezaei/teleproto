<!-- @generated -->

# contacts.getContacts

[Docs](https://core.telegram.org/method/contacts.getContacts)

Returns the current user's contact list.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| hash | long | * | [Hash used for caching, for more info click here](https://core.telegram.org/api/offsets#hash-generation).<br>Note that the hash is computed [using the usual algorithm](https://core.telegram.org/api/offsets#hash-generation), passing to the algorithm first the previously returned [contacts.contacts](../constructors/contacts.contacts.md).`saved_count` field, then max `100000` sorted user IDs from the contact list, including the ID of the currently logged in user if it is saved as a contact. <br>Example: [tdlib implementation](https://github.com/tdlib/td/blob/63c7d0301825b78c30dc7307f1f1466be049eb79/td/telegram/UserManager.cpp#L5754). |

## Returns

contacts.Contacts

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.

## Usage

```php
$request = Methods::contacts()->getContacts()
    ->hash(123)
    ->toRequest();

$result = TeleprotoClient::dispatch($request);
```
