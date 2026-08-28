<!-- @generated -->

# messages.forwardMessages

[Docs](https://core.telegram.org/method/messages.forwardMessages)

Forwards messages by their IDs.

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| silent | true |  | Whether to send messages silently (no notification will be triggered on the destination clients) |
| background | true |  | Whether to send the message in background |
| with_my_score | true |  | When forwarding games, whether to include your score in the game |
| drop_author | true |  | Whether to forward messages without quoting the original author |
| drop_media_captions | true |  | Whether to strip captions from media |
| noforwards | true |  | Only for bots, disallows further re-forwarding and saving of the messages, even if the destination chat doesn't have [content protection](https://telegram.org/blog/protected-content-delete-by-date-and-more) enabled |
| allow_paid_floodskip | true |  | Bots only: if set, allows sending up to 1000 messages per second, ignoring [broadcasting limits](https://core.telegram.org/bots/faq#how-can-i-message-all-of-my-bot-39s-subscribers-at-once) for a fee of 0.1 Telegram Stars per message. The relevant Stars will be withdrawn from the bot's balance. |
| from_ephemeral | true |  |  |
| from_peer | InputPeer | * | Source of messages |
| id | Vector<int> | * | IDs of messages |
| random_id | Vector<long> | * | Random ID to prevent resending of messages |
| to_peer | InputPeer | * | Destination peer |
| top_msg_id | int |  | Destination [forum topic](https://core.telegram.org/api/forum#forum-topics) |
| reply_to | InputReplyTo |  | Can only contain an [inputReplyToMonoForum](../constructors/inputReplyToMonoForum.md), to forward messages to a [monoforum topic](https://core.telegram.org/api/monoforum) (mutually exclusive with `top_msg_id`). |
| schedule_date | int |  | Scheduled message date for scheduled messages |
| schedule_repeat_period | int |  |  |
| send_as | InputPeer |  | Forward the messages as the specified peer |
| quick_reply_shortcut | InputQuickReplyShortcut |  | Add the messages to the specified [quick reply shortcut »](https://core.telegram.org/api/business#quick-reply-shortcuts), instead. |
| effect | long |  |  |
| video_timestamp | int |  | Start playing the video at the specified timestamp (seconds). |
| allow_paid_stars | long |  | For [paid messages »](https://core.telegram.org/api/paid-messages), specifies the amount of [Telegram Stars](https://core.telegram.org/api/stars) the user has agreed to pay in order to send the message. |
| suggested_post | SuggestedPost |  | Used to [suggest a post to a channel, see here »](https://core.telegram.org/api/suggested-posts) for more info on the full flow. |

## Returns

Updates

## Errors

- `ALLOW_PAYMENT_REQUIRED` — This peer only accepts [paid messages &raquo;](https://core.telegram.org/api/paid-messages): this error is only emitted for older layers without paid messages support, so the client must be updated in order to use paid messages.  .
- `ALLOW_PAYMENT_REQUIRED_%d` — This peer charges 30 [Telegram Stars](https://core.telegram.org/api/stars) per message, but the `allow_paid_stars` was not set or its value is smaller than 30.
- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BROADCAST_PUBLIC_VOTERS_FORBIDDEN` — You can't forward polls with public voters.
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `CHANNEL_INVALID` — The provided channel is invalid.
- `CHANNEL_PRIVATE` — You haven't joined this channel/supergroup.
- `CHAT_ADMIN_REQUIRED` — You must be an admin in this chat to do this.
- `CHAT_FORWARDS_RESTRICTED` — You can't forward messages from a protected chat.
- `CHAT_GUEST_SEND_FORBIDDEN` — You join the discussion group before commenting, see [here &raquo;](https://core.telegram.org/api/discussion#requiring-users-to-join-the-group) for more info.
- `CHAT_ID_INVALID` — The provided chat id is invalid.
- `CHAT_RESTRICTED` — You can't send messages in this chat, you were restricted.
- `CHAT_SEND_AUDIOS_FORBIDDEN` — You can't send audio messages in this chat.
- `CHAT_SEND_DOCS_FORBIDDEN` — You can't send documents in this chat.
- `CHAT_SEND_GAME_FORBIDDEN` — You can't send a game to this chat.
- `CHAT_SEND_GIFS_FORBIDDEN` — You can't send gifs in this chat.
- `CHAT_SEND_INLINE_FORBIDDEN` — You can't send inline messages in this group.
- `CHAT_SEND_MEDIA_FORBIDDEN` — You can't send media in this chat.
- `CHAT_SEND_PHOTOS_FORBIDDEN` — You can't send photos in this chat.
- `CHAT_SEND_PLAIN_FORBIDDEN` — You can't send non-media (text) messages in this chat.
- `CHAT_SEND_POLL_FORBIDDEN` — You can't send polls in this chat.
- `CHAT_SEND_STICKERS_FORBIDDEN` — You can't send stickers in this chat.
- `CHAT_SEND_VIDEOS_FORBIDDEN` — You can't send videos in this chat.
- `CHAT_SEND_VOICES_FORBIDDEN` — You can't send voice recordings in this chat.
- `CHAT_SEND_WEBPAGE_FORBIDDEN` — You can't send webpage previews to this chat.
- `CHAT_WRITE_FORBIDDEN` — You can't write in this chat.
- `GROUPED_MEDIA_INVALID` — Invalid grouped media.
- `INPUT_USER_DEACTIVATED` — The specified user was deleted.
- `MEDIA_EMPTY` — The provided media object is invalid.
- `MEDIA_FILE_INVALID` — The specified media file is invalid.
- `MESSAGE_IDS_EMPTY` — No message ids were provided.
- `MESSAGE_ID_INVALID` — The provided message id is invalid.
- `MSG_ID_INVALID` — Invalid message ID provided.
- `PAYMENT_UNSUPPORTED` — A detailed description of the error will be received separately as described [here &raquo;](https://core.telegram.org/api/errors#406-not-acceptable).
- `PEER_ID_INVALID` — The provided peer id is invalid.
- `PREMIUM_ACCOUNT_REQUIRED` — A premium account is required to execute this action.
- `PRIVACY_PREMIUM_REQUIRED` — You need a [Telegram Premium subscription](https://core.telegram.org/api/premium) to send a message to this user.
- `QUICK_REPLIES_BOT_NOT_ALLOWED` — [Quick replies](https://core.telegram.org/api/business#quick-reply-shortcuts) cannot be used by bots.
- `QUICK_REPLIES_TOO_MUCH` — A maximum of [appConfig.`quick_replies_limit`](https://core.telegram.org/api/config#quick-replies-limit) shortcuts may be created, the limit was reached.
- `QUIZ_ANSWER_MISSING` — You can forward a quiz while hiding the original author only after choosing an option in the quiz.
- `RANDOM_ID_DUPLICATE` — You provided a random ID that was already used.
- `RANDOM_ID_INVALID` — A provided random ID is invalid.
- `REPLY_MESSAGES_TOO_MUCH` — Each shortcut can contain a maximum of [appConfig.`quick_reply_messages_limit`](https://core.telegram.org/api/config#quick-reply-messages-limit) messages, the limit was reached.
- `REPLY_TO_MONOFORUM_PEER_INVALID` — The specified inputReplyToMonoForum.monoforum_peer_id is invalid.
- `SCHEDULE_BOT_NOT_ALLOWED` — Bots cannot schedule messages.
- `SCHEDULE_DATE_TOO_LATE` — You can't schedule a message this far in the future.
- `SCHEDULE_TOO_MUCH` — There are too many scheduled messages.
- `SEND_AS_PEER_INVALID` — You can't send messages as the specified peer.
- `SLOWMODE_MULTI_MSGS_DISABLED` — Slowmode is enabled, you cannot forward multiple messages to this group.
- `SLOWMODE_WAIT_%d` — Slowmode is enabled in this chat: wait 30 seconds before sending another message to this chat.
- `SUGGESTED_POST_PEER_INVALID` — You cannot send suggested posts to non-[monoforum](https://core.telegram.org/api/monoforum) peers.
- `TOPIC_CLOSED` — This topic was closed, you can't send messages to it anymore.
- `TOPIC_DELETED` — The specified topic was deleted.
- `USER_BANNED_IN_CHANNEL` — You're banned from sending messages in supergroups/channels.
- `USER_BOT_TO_BOT_DISABLED` — Bot-to-bot messaging is disabled because one of the two bots hasn't enabled the Bot to Bot setting in @BotFather.
- `USER_IS_BLOCKED` — You were blocked by this user.
- `USER_IS_BOT` — Bots can't send messages to other bots.
- `VOICE_MESSAGES_FORBIDDEN` — This user's privacy settings forbid you from sending voice messages.
- `YOU_BLOCKED_USER` — You blocked this user.

## Usage

```php
$request = Methods::messages()->forwardMessages()
    ->fromPeer(['_' => '…'])
    ->id(['_' => '…'])
    ->randomId(['_' => '…'])
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(defaultApiId: …, defaultApiHash: …)
$result = $client->dispatch($request);
```
