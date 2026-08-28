<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Methods\Generated;

/**
 * Bot API (bot-http) curated method builders.
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php from config/curated-methods.json — do not edit by hand.
 */
final class Bots
{
    public function getMe(): BotGetMeBuilder
    {
        return new BotGetMeBuilder();
    }

    public function sendMessage(): BotSendMessageBuilder
    {
        return new BotSendMessageBuilder();
    }

    public function sendPhoto(): BotSendPhotoBuilder
    {
        return new BotSendPhotoBuilder();
    }

    public function sendDocument(): BotSendDocumentBuilder
    {
        return new BotSendDocumentBuilder();
    }

    public function sendMediaGroup(): BotSendMediaGroupBuilder
    {
        return new BotSendMediaGroupBuilder();
    }

    public function editMessageText(): BotEditMessageTextBuilder
    {
        return new BotEditMessageTextBuilder();
    }

    public function deleteMessage(): BotDeleteMessageBuilder
    {
        return new BotDeleteMessageBuilder();
    }

    public function answerCallbackQuery(): BotAnswerCallbackQueryBuilder
    {
        return new BotAnswerCallbackQueryBuilder();
    }

    public function setWebhook(): BotSetWebhookBuilder
    {
        return new BotSetWebhookBuilder();
    }

    public function getUpdates(): BotGetUpdatesBuilder
    {
        return new BotGetUpdatesBuilder();
    }
}

/**
 * Fluent builder for getMe (bot-http, return: User).
 * A simple method for testing your bot's authentication token. Requires no parameters. Returns basic information about the bot in form of a User object.
 * Docs: https://core.telegram.org/bots/api#getme
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class BotGetMeBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        return array_merge(['_' => 'getMe'], $this->p);
    }
}

/**
 * Fluent builder for sendMessage (bot-http, return: Message).
 * Use this method to send text messages. On success, the sent Message is returned.
 * Docs: https://core.telegram.org/bots/api#sendmessage
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class BotSendMessageBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function businessConnectionId(string $business_connection_id): self
    {
        $this->p['business_connection_id'] = $business_connection_id;

        return $this;
    }

    public function chatId(int|string $chat_id): self
    {
        $this->p['chat_id'] = $chat_id;

        return $this;
    }

    public function messageThreadId(int $message_thread_id): self
    {
        $this->p['message_thread_id'] = $message_thread_id;

        return $this;
    }

    public function directMessagesTopicId(int $direct_messages_topic_id): self
    {
        $this->p['direct_messages_topic_id'] = $direct_messages_topic_id;

        return $this;
    }

    public function ephemeralMessageParameters(string $ephemeral_message_parameters): self
    {
        $this->p['ephemeral_message_parameters'] = $ephemeral_message_parameters;

        return $this;
    }

    public function text(string $text): self
    {
        $this->p['text'] = $text;

        return $this;
    }

    public function parseMode(string $parse_mode): self
    {
        $this->p['parse_mode'] = $parse_mode;

        return $this;
    }

    public function entities(array $entities): self
    {
        $this->p['entities'] = $entities;

        return $this;
    }

    public function linkPreviewOptions(string $link_preview_options): self
    {
        $this->p['link_preview_options'] = $link_preview_options;

        return $this;
    }

    public function disableNotification(bool $disable_notification): self
    {
        $this->p['disable_notification'] = $disable_notification;

        return $this;
    }

    public function protectContent(bool $protect_content): self
    {
        $this->p['protect_content'] = $protect_content;

        return $this;
    }

    public function allowPaidBroadcast(bool $allow_paid_broadcast): self
    {
        $this->p['allow_paid_broadcast'] = $allow_paid_broadcast;

        return $this;
    }

    public function messageEffectId(string $message_effect_id): self
    {
        $this->p['message_effect_id'] = $message_effect_id;

        return $this;
    }

    public function suggestedPostParameters(string $suggested_post_parameters): self
    {
        $this->p['suggested_post_parameters'] = $suggested_post_parameters;

        return $this;
    }

    public function replyParameters(string $reply_parameters): self
    {
        $this->p['reply_parameters'] = $reply_parameters;

        return $this;
    }

    public function replyMarkup(string $reply_markup): self
    {
        $this->p['reply_markup'] = $reply_markup;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['chat_id', 'text'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('sendMessage: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'sendMessage'], $this->p);
    }
}

/**
 * Fluent builder for sendPhoto (bot-http, return: Message).
 * Use this method to send photos. On success, the sent Message is returned.
 * Docs: https://core.telegram.org/bots/api#sendphoto
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class BotSendPhotoBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function businessConnectionId(string $business_connection_id): self
    {
        $this->p['business_connection_id'] = $business_connection_id;

        return $this;
    }

    public function chatId(int|string $chat_id): self
    {
        $this->p['chat_id'] = $chat_id;

        return $this;
    }

    public function messageThreadId(int $message_thread_id): self
    {
        $this->p['message_thread_id'] = $message_thread_id;

        return $this;
    }

    public function directMessagesTopicId(int $direct_messages_topic_id): self
    {
        $this->p['direct_messages_topic_id'] = $direct_messages_topic_id;

        return $this;
    }

    public function ephemeralMessageParameters(string $ephemeral_message_parameters): self
    {
        $this->p['ephemeral_message_parameters'] = $ephemeral_message_parameters;

        return $this;
    }

    public function photo(string $photo): self
    {
        $this->p['photo'] = $photo;

        return $this;
    }

    public function caption(string $caption): self
    {
        $this->p['caption'] = $caption;

        return $this;
    }

    public function parseMode(string $parse_mode): self
    {
        $this->p['parse_mode'] = $parse_mode;

        return $this;
    }

    public function captionEntities(array $caption_entities): self
    {
        $this->p['caption_entities'] = $caption_entities;

        return $this;
    }

    public function showCaptionAboveMedia(bool $show_caption_above_media): self
    {
        $this->p['show_caption_above_media'] = $show_caption_above_media;

        return $this;
    }

    public function hasSpoiler(bool $has_spoiler): self
    {
        $this->p['has_spoiler'] = $has_spoiler;

        return $this;
    }

    public function disableNotification(bool $disable_notification): self
    {
        $this->p['disable_notification'] = $disable_notification;

        return $this;
    }

    public function protectContent(bool $protect_content): self
    {
        $this->p['protect_content'] = $protect_content;

        return $this;
    }

    public function allowPaidBroadcast(bool $allow_paid_broadcast): self
    {
        $this->p['allow_paid_broadcast'] = $allow_paid_broadcast;

        return $this;
    }

    public function messageEffectId(string $message_effect_id): self
    {
        $this->p['message_effect_id'] = $message_effect_id;

        return $this;
    }

    public function suggestedPostParameters(string $suggested_post_parameters): self
    {
        $this->p['suggested_post_parameters'] = $suggested_post_parameters;

        return $this;
    }

    public function replyParameters(string $reply_parameters): self
    {
        $this->p['reply_parameters'] = $reply_parameters;

        return $this;
    }

    public function replyMarkup(string $reply_markup): self
    {
        $this->p['reply_markup'] = $reply_markup;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['chat_id', 'photo'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('sendPhoto: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'sendPhoto'], $this->p);
    }
}

/**
 * Fluent builder for sendDocument (bot-http, return: Message).
 * Use this method to send general files. On success, the sent Message is returned. Bots can currently send files of any type of up to 50 MB in size, this limit may be changed in the future.
 * Docs: https://core.telegram.org/bots/api#senddocument
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class BotSendDocumentBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function businessConnectionId(string $business_connection_id): self
    {
        $this->p['business_connection_id'] = $business_connection_id;

        return $this;
    }

    public function chatId(int|string $chat_id): self
    {
        $this->p['chat_id'] = $chat_id;

        return $this;
    }

    public function messageThreadId(int $message_thread_id): self
    {
        $this->p['message_thread_id'] = $message_thread_id;

        return $this;
    }

    public function directMessagesTopicId(int $direct_messages_topic_id): self
    {
        $this->p['direct_messages_topic_id'] = $direct_messages_topic_id;

        return $this;
    }

    public function ephemeralMessageParameters(string $ephemeral_message_parameters): self
    {
        $this->p['ephemeral_message_parameters'] = $ephemeral_message_parameters;

        return $this;
    }

    public function document(string $document): self
    {
        $this->p['document'] = $document;

        return $this;
    }

    public function thumbnail(string $thumbnail): self
    {
        $this->p['thumbnail'] = $thumbnail;

        return $this;
    }

    public function caption(string $caption): self
    {
        $this->p['caption'] = $caption;

        return $this;
    }

    public function parseMode(string $parse_mode): self
    {
        $this->p['parse_mode'] = $parse_mode;

        return $this;
    }

    public function captionEntities(array $caption_entities): self
    {
        $this->p['caption_entities'] = $caption_entities;

        return $this;
    }

    public function disableContentTypeDetection(bool $disable_content_type_detection): self
    {
        $this->p['disable_content_type_detection'] = $disable_content_type_detection;

        return $this;
    }

    public function disableNotification(bool $disable_notification): self
    {
        $this->p['disable_notification'] = $disable_notification;

        return $this;
    }

    public function protectContent(bool $protect_content): self
    {
        $this->p['protect_content'] = $protect_content;

        return $this;
    }

    public function allowPaidBroadcast(bool $allow_paid_broadcast): self
    {
        $this->p['allow_paid_broadcast'] = $allow_paid_broadcast;

        return $this;
    }

    public function messageEffectId(string $message_effect_id): self
    {
        $this->p['message_effect_id'] = $message_effect_id;

        return $this;
    }

    public function suggestedPostParameters(string $suggested_post_parameters): self
    {
        $this->p['suggested_post_parameters'] = $suggested_post_parameters;

        return $this;
    }

    public function replyParameters(string $reply_parameters): self
    {
        $this->p['reply_parameters'] = $reply_parameters;

        return $this;
    }

    public function replyMarkup(string $reply_markup): self
    {
        $this->p['reply_markup'] = $reply_markup;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['chat_id', 'document'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('sendDocument: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'sendDocument'], $this->p);
    }
}

/**
 * Fluent builder for sendMediaGroup (bot-http, return: Array of Message).
 * Use this method to send a group of photos, live photos, videos, documents or audios as an album. Documents and audio files can be only grouped in an album with messages of the same type. On success, an Array of Message objects that were sent is returned.
 * Docs: https://core.telegram.org/bots/api#sendmediagroup
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class BotSendMediaGroupBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function businessConnectionId(string $business_connection_id): self
    {
        $this->p['business_connection_id'] = $business_connection_id;

        return $this;
    }

    public function chatId(int|string $chat_id): self
    {
        $this->p['chat_id'] = $chat_id;

        return $this;
    }

    public function messageThreadId(int $message_thread_id): self
    {
        $this->p['message_thread_id'] = $message_thread_id;

        return $this;
    }

    public function directMessagesTopicId(int $direct_messages_topic_id): self
    {
        $this->p['direct_messages_topic_id'] = $direct_messages_topic_id;

        return $this;
    }

    public function media(array $media): self
    {
        $this->p['media'] = $media;

        return $this;
    }

    public function disableNotification(bool $disable_notification): self
    {
        $this->p['disable_notification'] = $disable_notification;

        return $this;
    }

    public function protectContent(bool $protect_content): self
    {
        $this->p['protect_content'] = $protect_content;

        return $this;
    }

    public function allowPaidBroadcast(bool $allow_paid_broadcast): self
    {
        $this->p['allow_paid_broadcast'] = $allow_paid_broadcast;

        return $this;
    }

    public function messageEffectId(string $message_effect_id): self
    {
        $this->p['message_effect_id'] = $message_effect_id;

        return $this;
    }

    public function replyParameters(string $reply_parameters): self
    {
        $this->p['reply_parameters'] = $reply_parameters;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['chat_id', 'media'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('sendMediaGroup: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'sendMediaGroup'], $this->p);
    }
}

/**
 * Fluent builder for editMessageText (bot-http, return: Message|Boolean).
 * Use this method to edit text, rich and game messages. On success, if the edited message is not an inline message, the edited Message is returned, otherwise True is returned. Note that business messages that were not sent by the bot and do not contain an inline keyboard can only be edited within 48 hours from the time they were sent.
 * Docs: https://core.telegram.org/bots/api#editmessagetext
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class BotEditMessageTextBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function businessConnectionId(string $business_connection_id): self
    {
        $this->p['business_connection_id'] = $business_connection_id;

        return $this;
    }

    public function chatId(int|string $chat_id): self
    {
        $this->p['chat_id'] = $chat_id;

        return $this;
    }

    public function messageId(int $message_id): self
    {
        $this->p['message_id'] = $message_id;

        return $this;
    }

    public function inlineMessageId(string $inline_message_id): self
    {
        $this->p['inline_message_id'] = $inline_message_id;

        return $this;
    }

    public function text(string $text): self
    {
        $this->p['text'] = $text;

        return $this;
    }

    public function parseMode(string $parse_mode): self
    {
        $this->p['parse_mode'] = $parse_mode;

        return $this;
    }

    public function entities(array $entities): self
    {
        $this->p['entities'] = $entities;

        return $this;
    }

    public function linkPreviewOptions(string $link_preview_options): self
    {
        $this->p['link_preview_options'] = $link_preview_options;

        return $this;
    }

    public function richMessage(string $rich_message): self
    {
        $this->p['rich_message'] = $rich_message;

        return $this;
    }

    public function replyMarkup(string $reply_markup): self
    {
        $this->p['reply_markup'] = $reply_markup;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        return array_merge(['_' => 'editMessageText'], $this->p);
    }
}

/**
 * Fluent builder for deleteMessage (bot-http, return: Boolean).
 * Use this method to delete a message, including service messages, with the following limitations: - A message can only be deleted if it was sent less than 48 hours ago. - Service messages about a supergroup, channel, or forum topic creation can't be deleted. - A dice message in a private chat can only be deleted if it was sent more than 24 hours ago. - Bots can delete outgoing messages in private chats, groups, and supergroups. - Bots can delete incoming messages in private chats. - Bots granted can_post_messages permissions can delete outgoing messages in channels. - If the bot is an administrator of a group, it can delete any message there. - If the bot has can_delete_messages administrator right in a supergroup or a channel, it can delete any message there. - If the bot has can_manage_direct_messages administrator right in a channel, it can delete any message in the corresponding direct messages chat. Returns True on success.
 * Docs: https://core.telegram.org/bots/api#deletemessage
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class BotDeleteMessageBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function chatId(int|string $chat_id): self
    {
        $this->p['chat_id'] = $chat_id;

        return $this;
    }

    public function messageId(int $message_id): self
    {
        $this->p['message_id'] = $message_id;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['chat_id', 'message_id'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('deleteMessage: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'deleteMessage'], $this->p);
    }
}

/**
 * Fluent builder for answerCallbackQuery (bot-http, return: Boolean).
 * Use this method to send answers to callback queries sent from inline keyboards. The answer will be displayed to the user as a notification at the top of the chat screen or as an alert. On success, True is returned.
 * Docs: https://core.telegram.org/bots/api#answercallbackquery
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class BotAnswerCallbackQueryBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function callbackQueryId(string $callback_query_id): self
    {
        $this->p['callback_query_id'] = $callback_query_id;

        return $this;
    }

    public function text(string $text): self
    {
        $this->p['text'] = $text;

        return $this;
    }

    public function showAlert(bool $show_alert): self
    {
        $this->p['show_alert'] = $show_alert;

        return $this;
    }

    public function url(string $url): self
    {
        $this->p['url'] = $url;

        return $this;
    }

    public function cacheTime(int $cache_time): self
    {
        $this->p['cache_time'] = $cache_time;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['callback_query_id'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('answerCallbackQuery: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'answerCallbackQuery'], $this->p);
    }
}

/**
 * Fluent builder for setWebhook (bot-http, return: Boolean).
 * Use this method to specify a URL and receive incoming updates via an outgoing webhook. Whenever there is an update for the bot, we will send an HTTPS POST request to the specified URL, containing a JSON-serialized Update. In case of an unsuccessful request (a request with response HTTP status code different from 2XY), we will repeat the request and give up after a reasonable amount of attempts. Returns True on success. If you'd like to make sure that the webhook was set by you, you can specify secret data in the parameter secret_token. If specified, the request will contain a header "X-Telegram-Bot-Api-Secret-Token" with the secret token as content.
 * Docs: https://core.telegram.org/bots/api#setwebhook
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class BotSetWebhookBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function url(string $url): self
    {
        $this->p['url'] = $url;

        return $this;
    }

    public function certificate(string $certificate): self
    {
        $this->p['certificate'] = $certificate;

        return $this;
    }

    public function ipAddress(string $ip_address): self
    {
        $this->p['ip_address'] = $ip_address;

        return $this;
    }

    public function maxConnections(int $max_connections): self
    {
        $this->p['max_connections'] = $max_connections;

        return $this;
    }

    public function allowedUpdates(array $allowed_updates): self
    {
        $this->p['allowed_updates'] = $allowed_updates;

        return $this;
    }

    public function dropPendingUpdates(bool $drop_pending_updates): self
    {
        $this->p['drop_pending_updates'] = $drop_pending_updates;

        return $this;
    }

    public function secretToken(string $secret_token): self
    {
        $this->p['secret_token'] = $secret_token;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['url'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('setWebhook: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'setWebhook'], $this->p);
    }
}

/**
 * Fluent builder for getUpdates (bot-http, return: Array of Update).
 * Use this method to receive incoming updates using long polling (wiki). Returns an Array of Update objects.
 * Docs: https://core.telegram.org/bots/api#getupdates
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class BotGetUpdatesBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function offset(int $offset): self
    {
        $this->p['offset'] = $offset;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->p['limit'] = $limit;

        return $this;
    }

    public function timeout(int $timeout): self
    {
        $this->p['timeout'] = $timeout;

        return $this;
    }

    public function allowedUpdates(array $allowed_updates): self
    {
        $this->p['allowed_updates'] = $allowed_updates;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        return array_merge(['_' => 'getUpdates'], $this->p);
    }
}
