<!-- @generated -->

# messages.sendMessage

[Docs](https://core.telegram.org/method/messages.sendMessage)

Sends a message to a chat

## Parameters

| name | type | required | description |
| --- | --- | --- | --- |
| no_webpage | true |  | Set this flag to disable generation of the webpage preview |
| silent | true |  | Send this message silently (no notifications for the receivers) |
| background | true |  | Send this message as background message |
| clear_draft | true |  | Clear the draft field |
| noforwards | true |  | Only for bots, disallows forwarding and saving of the messages, even if the destination chat doesn't have [content protection](https://telegram.org/blog/protected-content-delete-by-date-and-more) enabled |
| update_stickersets_order | true |  | Whether to move used stickersets to top, [see here for more info on this flag »](https://core.telegram.org/api/stickers#recent-stickersets) |
| invert_media | true |  | If set, any eventual webpage preview will be shown on top of the message instead of at the bottom. |
| allow_paid_floodskip | true |  | Bots only: if set, allows sending up to 1000 messages per second, ignoring [broadcasting limits](https://core.telegram.org/bots/faq#how-can-i-message-all-of-my-bot-39s-subscribers-at-once) for a fee of 0.1 Telegram Stars per message. The relevant Stars will be withdrawn from the bot's balance. |
| peer | InputPeer | * | The destination where the message will be sent |
| reply_to | InputReplyTo |  | If set, indicates that the message should be sent in reply to the specified message or story. <br>Also used to quote other messages. |
| message | string | * | The message |
| random_id | long | * | Unique client message ID required to prevent message resending |
| reply_markup | ReplyMarkup |  | Reply markup for sending bot buttons |
| entities | Vector<MessageEntity> |  | Message [entities](https://core.telegram.org/api/entities) for sending styled text |
| schedule_date | int |  | Scheduled message date for [scheduled messages](https://core.telegram.org/api/scheduled-messages) |
| schedule_repeat_period | int |  |  |
| send_as | InputPeer |  | Send this message as the specified peer |
| quick_reply_shortcut | InputQuickReplyShortcut |  | Add the message to the specified [quick reply shortcut »](https://core.telegram.org/api/business#quick-reply-shortcuts), instead. |
| effect | long |  | Specifies a [message effect »](https://core.telegram.org/api/effects) to use for the message. |
| allow_paid_stars | long |  | For [paid messages »](https://core.telegram.org/api/paid-messages), specifies the amount of [Telegram Stars](https://core.telegram.org/api/stars) the user has agreed to pay in order to send the message. |
| suggested_post | SuggestedPost |  | Used to [suggest a post to a channel, see here »](https://core.telegram.org/api/suggested-posts) for more info on the full flow. |
| rich_message | InputRichMessage |  |  |

## Returns

Updates

## Errors

- `ADMIN_RIGHTS_EMPTY` — The chatAdminRights constructor passed in keyboardButtonRequestPeer.peer_type.user_admin_rights has no rights set (i.e. flags is 0).
- `ALLOW_PAYMENT_REQUIRED` — This peer only accepts [paid messages &raquo;](https://core.telegram.org/api/paid-messages): this error is only emitted for older layers without paid messages support, so the client must be updated in order to use paid messages.  .
- `ALLOW_PAYMENT_REQUIRED_%d` — This peer charges 30 [Telegram Stars](https://core.telegram.org/api/stars) per message, but the `allow_paid_stars` was not set or its value is smaller than 30.
- `AUTH_KEY_UNREGISTERED` — The specified authorization key is not registered in the system (for example, a PFS temporary key has expired).
- `BALANCE_TOO_LOW` — The transaction cannot be completed because the current [Telegram Stars balance](https://core.telegram.org/api/stars) is too low.
- `BOT_DOMAIN_INVALID` — Bot domain invalid.
- `BOT_INVALID` — This is not a valid bot.
- `BUSINESS_CONNECTION_INVALID` — The `connection_id` passed to the wrapping [invokeWithBusinessConnection](https://core.telegram.org/api/business) call is invalid.
- `BUSINESS_CONNECTION_NOT_ALLOWED` — This method was invoked over a business connection using [invokeWithBusinessConnection](https://core.telegram.org/api/business#connected-bots), but either (1) we're a user, and users cannot invoke methods over a business connection; (2) we're a bot, but business mode was disabled in @botfather or (3); we're a bot, but this method cannot be invoked over a business connection.
- `BUSINESS_PEER_INVALID` — Messages can't be set to the specified peer through the current [business connection](https://core.telegram.org/api/business#connected-bots).
- `BUSINESS_PEER_USAGE_MISSING` — You cannot send a message to a user through a [business connection](https://core.telegram.org/api/business#connected-bots) if the user hasn't recently contacted us.
- `BUTTON_COPY_TEXT_INVALID` — The specified [keyboardButtonCopy](https://core.telegram.org/constructor/keyboardButtonCopy).`copy_text` is invalid.
- `BUTTON_DATA_INVALID` — The data of one or more of the buttons you provided is invalid.
- `BUTTON_ID_INVALID` — The specified button ID is invalid.
- `BUTTON_TYPE_INVALID` — The type of one or more of the buttons you provided is invalid.
- `BUTTON_URL_INVALID` — Button URL invalid.
- `BUTTON_USER_INVALID` — The `user_id` passed to inputKeyboardButtonUserProfile is invalid!
- `BUTTON_USER_PRIVACY_RESTRICTED` — The privacy setting of the user specified in a [inputKeyboardButtonUserProfile](https://core.telegram.org/constructor/inputKeyboardButtonUserProfile) button do not allow creating such a button.
- `CHANNEL_INVALID` — The provided channel is invalid.
- `CHANNEL_MONOFORUM_UNSUPPORTED` — [Monoforums](https://core.telegram.org/api/channel#monoforums) do not support this feature.
- `CHANNEL_PRIVATE` — You haven't joined this channel/supergroup.
- `CHAT_ADMIN_REQUIRED` — You must be an admin in this chat to do this.
- `CHAT_FORWARDS_RESTRICTED` — You can't forward messages from a protected chat.
- `CHAT_GUEST_SEND_FORBIDDEN` — You join the discussion group before commenting, see [here &raquo;](https://core.telegram.org/api/discussion#requiring-users-to-join-the-group) for more info.
- `CHAT_ID_INVALID` — The provided chat id is invalid.
- `CHAT_RESTRICTED` — You can't send messages in this chat, you were restricted.
- `CHAT_SEND_PLAIN_FORBIDDEN` — You can't send non-media (text) messages in this chat.
- `CHAT_WRITE_FORBIDDEN` — You can't write in this chat.
- `DOCUMENT_INVALID` — The specified document is invalid.
- `EFFECT_CHAT_INVALID` — Message [effects](https://core.telegram.org/api/effects) can only be used in private 1-on-1 chats, but the caller tried to send a message with an effect to a group or channel.
- `ENCRYPTION_DECLINED` — The secret chat was declined.
- `ENTITIES_TOO_LONG` — You provided too many styled message entities.
- `ENTITY_BOUNDS_INVALID` — A specified [entity offset or length](https://core.telegram.org/api/entities#entity-length) is invalid, see [here &raquo;](https://core.telegram.org/api/entities#entity-length) for info on how to properly compute the entity offset/length.
- `ENTITY_DATE_FORMAT_INVALID` — One of the passed messageEntityFormattedDate objects has an invalid format (i.e. an invalid combination of the format flags).
- `ENTITY_DATE_INVALID` — One of the passed messageEntityFormattedDate objects has an invalid date: the allowed value ranges from `0` to the current date plus 1098 days (`time()+1098*86400`).
- `ENTITY_DATE_TOO_LONG` — The maximum text span that can be covered by a date entity is 31 UTF-16 code units if any of the date formatting flags is set, or 127 UTF-16 code units without.  .
- `ENTITY_MENTION_USER_INVALID` — You mentioned an invalid user.
- `FROM_MESSAGE_BOT_DISABLED` — Bots can't use fromMessage min constructors.
- `INPUT_USER_DEACTIVATED` — The specified user was deleted.
- `MESSAGE_EMPTY` — The provided message is empty.
- `MESSAGE_TOO_LONG` — The provided message is too long.
- `MSG_ID_INVALID` — Invalid message ID provided.
- `MSG_WAIT_FAILED` — A waiting call returned an error.
- `PAYMENT_UNSUPPORTED` — A detailed description of the error will be received separately as described [here &raquo;](https://core.telegram.org/api/errors#406-not-acceptable).
- `PEER_ID_INVALID` — The provided peer id is invalid.
- `PEER_TYPES_INVALID` — The passed [keyboardButtonSwitchInline](https://core.telegram.org/constructor/keyboardButtonSwitchInline).`peer_types` field is invalid.
- `PINNED_DIALOGS_TOO_MUCH` — Too many pinned dialogs.
- `POLL_OPTION_INVALID` — Invalid poll option provided.
- `PREMIUM_ACCOUNT_REQUIRED` — A premium account is required to execute this action.
- `PRIVACY_PREMIUM_REQUIRED` — You need a [Telegram Premium subscription](https://core.telegram.org/api/premium) to send a message to this user.
- `QUICK_REPLIES_BOT_NOT_ALLOWED` — [Quick replies](https://core.telegram.org/api/business#quick-reply-shortcuts) cannot be used by bots.
- `QUICK_REPLIES_TOO_MUCH` — A maximum of [appConfig.`quick_replies_limit`](https://core.telegram.org/api/config#quick-replies-limit) shortcuts may be created, the limit was reached.
- `QUOTE_TEXT_INVALID` — The specified `reply_to`.`quote_text` field is invalid.
- `RANDOM_ID_DUPLICATE` — You provided a random ID that was already used.
- `RANDOM_ID_EMPTY` — Random ID empty.
- `REPLY_MARKUP_INVALID` — The provided reply markup is invalid.
- `REPLY_MARKUP_TOO_LONG` — The specified reply_markup is too long.
- `REPLY_MESSAGES_TOO_MUCH` — Each shortcut can contain a maximum of [appConfig.`quick_reply_messages_limit`](https://core.telegram.org/api/config#quick-reply-messages-limit) messages, the limit was reached.
- `REPLY_MESSAGE_ID_INVALID` — The specified reply-to message ID is invalid.
- `REPLY_TO_INVALID` — The specified `reply_to` field is invalid.
- `REPLY_TO_MONOFORUM_PEER_INVALID` — The specified inputReplyToMonoForum.monoforum_peer_id is invalid.
- `REPLY_TO_USER_INVALID` — The replied-to user is invalid.
- `SCHEDULE_BOT_NOT_ALLOWED` — Bots cannot schedule messages.
- `SCHEDULE_DATE_TOO_LATE` — You can't schedule a message this far in the future.
- `SCHEDULE_STATUS_PRIVATE` — Can't schedule until user is online, if the user's last seen timestamp is hidden by their privacy settings.
- `SCHEDULE_TOO_MUCH` — There are too many scheduled messages.
- `SEND_AS_PEER_INVALID` — You can't send messages as the specified peer.
- `SLOWMODE_WAIT_%d` — Slowmode is enabled in this chat: wait 30 seconds before sending another message to this chat.
- `STORIES_NEVER_CREATED` — This peer hasn't ever posted any stories.
- `STORY_ID_INVALID` — The specified story ID is invalid.
- `SUGGESTED_POST_AMOUNT_INVALID` — The specified price for the suggested post is invalid.
- `SUGGESTED_POST_PEER_INVALID` — You cannot send suggested posts to non-[monoforum](https://core.telegram.org/api/monoforum) peers.
- `TOPIC_CLOSED` — This topic was closed, you can't send messages to it anymore.
- `TOPIC_DELETED` — The specified topic was deleted.
- `USER_BANNED_IN_CHANNEL` — You're banned from sending messages in supergroups/channels.
- `USER_BOT_TO_BOT_DISABLED` — Bot-to-bot messaging is disabled because one of the two bots hasn't enabled the Bot to Bot setting in @BotFather.
- `USER_IS_BLOCKED` — You were blocked by this user.
- `USER_IS_BOT` — Bots can't send messages to other bots.
- `WC_CONVERT_URL_INVALID` — WC convert URL invalid.
- `YOU_BLOCKED_USER` — You blocked this user.

## Usage

```php
$request = Methods::messages()->sendMessage()
    ->peer(['_' => '…'])
    ->message('text')
    ->randomId(123)
    ->toRequest();

$client = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class);   // or: new TeleprotoClient(defaultApiId: …, defaultApiHash: …)
$result = $client->dispatch($request);
```
