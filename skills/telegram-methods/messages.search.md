<!-- @generated -->

# messages.search

[Docs](https://core.telegram.org/method/messages.search)

Search for messages.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| peer | InputPeer | * | User or chat, histories with which are searched, or [(inputPeerEmpty)](../constructors/inputPeerEmpty.md) constructor to search in all private chats and [normal groups (not channels) »](https://core.telegram.org/api/channel). Use [messages.searchGlobal](../methods/messages.searchGlobal.md) to search globally in all chats, groups, supergroups and channels. |
| q | string | * | Text search request |
| from_id | InputPeer |  | Only return messages sent by the specified user ID |
| saved_peer_id | InputPeer |  | Search within the [saved message dialog »](https://core.telegram.org/api/saved-messages) with this ID. |
| saved_reaction | Vector<Reaction> |  | You may search for [saved messages tagged »](https://core.telegram.org/api/saved-messages#tags) with one or more reactions using this flag. |
| top_msg_id | int |  | [Thread ID](https://core.telegram.org/api/threads) |
| filter | MessagesFilter | * | Filter to return only specified message types |
| min_date | int | * | If a positive value was transferred, only messages with a sending date bigger than the transferred one will be returned |
| max_date | int | * | If a positive value was transferred, only messages with a sending date smaller than the transferred one will be returned |
| offset_id | int | * | Only return messages starting from the specified message ID |
| add_offset | int | * | [Additional offset](https://core.telegram.org/api/offsets) |
| limit | int | * | [Number of results to return](https://core.telegram.org/api/offsets), can be 0 to only return the message counter. |
| max_id | int | * | [Maximum message ID to return](https://core.telegram.org/api/offsets) |
| min_id | int | * | [Minimum message ID to return](https://core.telegram.org/api/offsets) |
| hash | long | * | [Hash](https://core.telegram.org/api/offsets) |

## Returns

messages.Messages

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `CHANNEL_INVALID` — The provided channel is invalid.
- `CHANNEL_PRIVATE` — You haven't joined this channel/supergroup.
- `CHAT_ADMIN_REQUIRED` — You must be an admin in this chat to do this.
- `CHAT_ID_INVALID` — The provided chat id is invalid.
- `FROM_PEER_INVALID` — The specified from_id is invalid.
- `INPUT_FILTER_INVALID` — The specified filter is invalid.
- `INPUT_USER_DEACTIVATED` — The specified user was deleted.
- `MSG_ID_INVALID` — Invalid message ID provided.
- `PEER_ID_INVALID` — The provided peer id is invalid.
- `PEER_ID_NOT_SUPPORTED` — The provided peer ID is not supported.
- `SEARCH_QUERY_EMPTY` — The search query is empty.
- `TAKEOUT_INVALID` — The specified takeout ID is invalid.
- `USER_ID_INVALID` — The provided user ID is invalid.

## Usage

```php
$request = Methods::messages()->search()
    ->peer(['_' => '…'])
    ->q('text')
    ->filter(['_' => '…'])
    ->toRequest();

$result = TeleprotoClient::dispatch($request);
```
