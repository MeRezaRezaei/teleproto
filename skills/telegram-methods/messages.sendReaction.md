<!-- @generated -->

# messages.sendReaction

[Docs](https://core.telegram.org/method/messages.sendReaction)

React to message.

Starting from layer 159, the reaction will be sent from the peer specified using [messages.saveDefaultSendAs](../methods/messages.saveDefaultSendAs.md).

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| big | true |  | Whether a bigger and longer reaction should be shown |
| add_to_recent | true |  | Whether to add this reaction to the [recent reactions list »](https://core.telegram.org/api/reactions#recent-reactions). |
| peer | InputPeer | * | Peer |
| msg_id | int | * | Message ID to react to |
| reaction | Vector<Reaction> |  | A list of reactions (doesn't accept [reactionPaid](../constructors/reactionPaid.md) constructors, use [messages.sendPaidReaction](../methods/messages.sendPaidReaction.md) to send paid reactions, instead). |

## Returns

Updates

## Errors

- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `CHANNEL_INVALID` — The provided channel is invalid.
- `CHANNEL_PRIVATE` — You haven't joined this channel/supergroup.
- `CHAT_WRITE_FORBIDDEN` — You can't write in this chat.
- `CUSTOM_REACTIONS_TOO_MANY` — Too many custom reactions were specified.
- `DOCUMENT_INVALID` — The specified document is invalid.
- `MESSAGE_ID_INVALID` — The provided message id is invalid.
- `MESSAGE_NOT_MODIFIED` — The provided message data is identical to the previous message data, the message wasn't modified.
- `MSG_ID_INVALID` — Invalid message ID provided.
- `PEER_ID_INVALID` — The provided peer id is invalid.
- `PREMIUM_ACCOUNT_REQUIRED` — A premium account is required to execute this action.
- `REACTIONS_TOO_MANY` — The message already has exactly `reactions_uniq_max` reaction emojis, you can't react with a new emoji, see [the docs for more info &raquo;](https://core.telegram.org/api/config#client-configuration).
- `REACTION_EMPTY` — Empty reaction provided.
- `REACTION_INVALID` — The specified reaction is invalid.
- `USER_BANNED_IN_CHANNEL` — You're banned from sending messages in supergroups/channels.

## Usage

```php
$request = Methods::messages()->sendReaction()
    ->peer(['_' => '…'])
    ->msgId(123)
    ->toRequest();

$result = TeleprotoClient::dispatch($request);
```
