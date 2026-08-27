<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\Types\InlineKeyboard;
use RuntimeException;

/**
 * Modern Laravel Bot Client for Telegram Bot API.
 * High-performance Bot API client powered by Laravel's Http client with retries, proxy routing, and test fakes.
 *
 * @see \MeRezaRezaei\Teleproto\Types\InlineKeyboard
 * @see \MeRezaRezaei\Teleproto\Types\InputMedia
 * @see \MeRezaRezaei\Teleproto\Entities\EntityParser
 */
class BotClient
{
    public const API_BASE_URL = 'https://api.telegram.org/bot';

    protected HttpFactory $http;

    public function __construct(
        public string $botToken,
        public ?array $proxyConfig = null,
        public int $timeout = 30,
        public int $retries = 3,
        ?HttpFactory $http = null
    ) {
        $this->http = $http ?? new HttpFactory();
    }

    /**
     * Executes any Telegram Bot API method.
     *
     * @param string $method Bot API method name (e.g. 'getMe', 'sendMessage', 'setWebhook')
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        if (empty($this->botToken)) {
            throw new RuntimeException("Telegram Bot token is required.");
        }

        $url = self::API_BASE_URL . $this->botToken . '/' . $method;

        $request = $this->http->timeout($this->timeout);

        if ($this->retries > 0) {
            $request = $request->retry($this->retries, 100, throw: false);
        }

        if (!empty($this->proxyConfig['host']) && !empty($this->proxyConfig['port'])) {
            $proxyType = strtolower($this->proxyConfig['type'] ?? 'socks5');
            $auth = '';
            if (!empty($this->proxyConfig['username']) && !empty($this->proxyConfig['password'])) {
                $auth = "{$this->proxyConfig['username']}:{$this->proxyConfig['password']}@";
            }
            $proxyUrl = "{$proxyType}://{$auth}{$this->proxyConfig['host']}:{$this->proxyConfig['port']}";
            $request = $request->withOptions(['proxy' => $proxyUrl]);
        }

        $response = $request->asJson()->post($url, $params);

        $json = $response->json();

        if ($json === null && $response->body() === '') {
            return [
                'ok' => true,
                'result' => [
                    '_' => 'bot_result',
                    'method' => $method,
                    'params' => $params,
                ],
            ];
        }

        if (!$json || empty($json['ok'])) {
            $errorCode = is_array($json) ? ($json['error_code'] ?? $response->status()) : $response->status();
            $description = is_array($json) ? ($json['description'] ?? 'Telegram Bot API error') : 'Telegram Bot API error';
            throw new TelegramException("Telegram Bot API [{$errorCode}]: {$description}", (int)$errorCode);
        }

        return $json;
    }

    /**
     * A simple method for testing your bot's authentication token.
     *
     * @return array<string, mixed> Basic information about the bot in form of a User object
     */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * Send a text message to a channel or user.
     *
     * @param int|string $chatId Target Chat ID or @channel username
     * @param string $text Message content
     * @param array<string, mixed> $options Additional parameters (parse_mode, reply_markup, entities, etc.)
     * @return array<string, mixed> Sent Message object
     *
     * @see \MeRezaRezaei\Teleproto\Types\InlineKeyboard
     * @see \MeRezaRezaei\Teleproto\Entities\EntityParser
     */
    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Forwards a message of any kind.
     *
     * @param int|string $chatId Target Chat ID
     * @param int|string $fromChatId Source Chat ID
     * @param int $messageId Message ID to forward
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function forwardMessage(int|string $chatId, int|string $fromChatId, int $messageId, array $options = []): array
    {
        return $this->call('forwardMessage', array_merge([
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
        ], $options));
    }

    /**
     * Copies a message of any kind without linking to the original message.
     *
     * @param int|string $chatId Target Chat ID
     * @param int|string $fromChatId Source Chat ID
     * @param int $messageId Message ID to copy
     * @param array<string, mixed> $options
     * @return array<string, mixed> MessageId of sent message
     */
    public function copyMessage(int|string $chatId, int|string $fromChatId, int $messageId, array $options = []): array
    {
        return $this->call('copyMessage', array_merge([
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send a photo to a chat.
     *
     * @param int|string $chatId Target Chat ID or @channel
     * @param string $photo File ID, HTTP URL, or attach://
     * @param string $caption Optional photo caption
     * @param array<string, mixed> $options (parse_mode, reply_markup, has_spoiler, etc.)
     * @return array<string, mixed>
     */
    public function sendPhoto(int|string $chatId, string $photo, string $caption = '', array $options = []): array
    {
        return $this->call('sendPhoto', array_merge([
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send an audio file to display in the Telegram music player.
     *
     * @param int|string $chatId Target Chat ID
     * @param string $audio File ID or HTTP URL
     * @param string $caption Optional audio caption
     * @param array<string, mixed> $options (duration, performer, title, etc.)
     * @return array<string, mixed>
     */
    public function sendAudio(int|string $chatId, string $audio, string $caption = '', array $options = []): array
    {
        return $this->call('sendAudio', array_merge([
            'chat_id' => $chatId,
            'audio' => $audio,
            'caption' => $caption,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send a general document file.
     *
     * @param int|string $chatId Target Chat ID
     * @param string $document File ID or HTTP URL
     * @param string $caption Optional document caption
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendDocument(int|string $chatId, string $document, string $caption = '', array $options = []): array
    {
        return $this->call('sendDocument', array_merge([
            'chat_id' => $chatId,
            'document' => $document,
            'caption' => $caption,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send a video file.
     *
     * @param int|string $chatId Target Chat ID
     * @param string $video File ID or HTTP URL
     * @param string $caption Optional caption
     * @param array<string, mixed> $options (duration, width, height, supports_streaming, etc.)
     * @return array<string, mixed>
     */
    public function sendVideo(int|string $chatId, string $video, string $caption = '', array $options = []): array
    {
        return $this->call('sendVideo', array_merge([
            'chat_id' => $chatId,
            'video' => $video,
            'caption' => $caption,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send an animation (GIF or H.264 video without sound).
     *
     * @param int|string $chatId Target Chat ID
     * @param string $animation File ID or HTTP URL
     * @param string $caption Optional caption
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendAnimation(int|string $chatId, string $animation, string $caption = '', array $options = []): array
    {
        return $this->call('sendAnimation', array_merge([
            'chat_id' => $chatId,
            'animation' => $animation,
            'caption' => $caption,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send a voice audio recording (.OGG with OPUS).
     *
     * @param int|string $chatId Target Chat ID
     * @param string $voice File ID or HTTP URL
     * @param string $caption Optional caption
     * @param array<string, mixed> $options (duration, etc.)
     * @return array<string, mixed>
     */
    public function sendVoice(int|string $chatId, string $voice, string $caption = '', array $options = []): array
    {
        return $this->call('sendVoice', array_merge([
            'chat_id' => $chatId,
            'voice' => $voice,
            'caption' => $caption,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send a round video note message.
     *
     * @param int|string $chatId Target Chat ID
     * @param string $videoNote File ID or HTTP URL
     * @param array<string, mixed> $options (duration, length, etc.)
     * @return array<string, mixed>
     */
    public function sendVideoNote(int|string $chatId, string $videoNote, array $options = []): array
    {
        return $this->call('sendVideoNote', array_merge([
            'chat_id' => $chatId,
            'video_note' => $videoNote,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send a group of photos, videos, documents or audios as an album.
     *
     * @param int|string $chatId Target Chat ID
     * @param list<array<string, mixed>> $media Array of InputMediaPhoto, InputMediaVideo, etc.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     *
     * @see \MeRezaRezaei\Teleproto\Types\InputMedia
     */
    public function sendMediaGroup(int|string $chatId, array $media, array $options = []): array
    {
        return $this->call('sendMediaGroup', array_merge([
            'chat_id' => $chatId,
            'media' => $media,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send a point on the map.
     *
     * @param int|string $chatId Target Chat ID
     * @param float $latitude Latitude of the location
     * @param float $longitude Longitude of the location
     * @param array<string, mixed> $options (horizontal_accuracy, live_period, etc.)
     * @return array<string, mixed>
     */
    public function sendLocation(int|string $chatId, float $latitude, float $longitude, array $options = []): array
    {
        return $this->call('sendLocation', array_merge([
            'chat_id' => $chatId,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send information about a venue.
     *
     * @param int|string $chatId Target Chat ID
     * @param float $latitude Latitude
     * @param float $longitude Longitude
     * @param string $title Venue name
     * @param string $address Venue address
     * @param array<string, mixed> $options (foursquare_id, google_place_id, etc.)
     * @return array<string, mixed>
     */
    public function sendVenue(int|string $chatId, float $latitude, float $longitude, string $title, string $address, array $options = []): array
    {
        return $this->call('sendVenue', array_merge([
            'chat_id' => $chatId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'title' => $title,
            'address' => $address,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send a phone contact to a chat.
     *
     * @param int|string $chatId Target Chat ID
     * @param string $phoneNumber Contact phone number
     * @param string $firstName Contact first name
     * @param array<string, mixed> $options (last_name, vcard, etc.)
     * @return array<string, mixed>
     */
    public function sendContact(int|string $chatId, string $phoneNumber, string $firstName, array $options = []): array
    {
        return $this->call('sendContact', array_merge([
            'chat_id' => $chatId,
            'phone_number' => $phoneNumber,
            'first_name' => $firstName,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Send a native poll.
     *
     * @param int|string $chatId Target Chat ID
     * @param string $question Poll question (1-300 chars)
     * @param list<string|array{text: string}> $options List of 2-10 answer options
     * @param array<string, mixed> $extra (is_anonymous, type, allows_multiple_answers, correct_option_id, explanation)
     * @return array<string, mixed>
     */
    public function sendPoll(int|string $chatId, string $question, array $options, array $extra = []): array
    {
        return $this->call('sendPoll', array_merge([
            'chat_id' => $chatId,
            'question' => $question,
            'options' => $options,
        ], $this->normalizeOptions($extra)));
    }

    /**
     * Send an animated dice emoji.
     *
     * @param int|string $chatId Target Chat ID
     * @param string $emoji Emoji for the dice (🎲, 🎯, 🏀, ⚽, 🎳, 🎰)
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendDice(int|string $chatId, string $emoji = '🎲', array $options = []): array
    {
        return $this->call('sendDice', array_merge([
            'chat_id' => $chatId,
            'emoji' => $emoji,
        ], $this->normalizeOptions($options)));
    }

    /**
     * Tell the user that something is happening on the bot's side (e.g. typing, upload_photo, record_video).
     *
     * @param int|string $chatId Target Chat ID
     * @param string $action 'typing', 'upload_photo', 'record_video', 'upload_video', 'record_voice', 'upload_voice', 'upload_document', 'choose_sticker', 'find_location', 'record_video_note', 'upload_video_note'
     * @return array<string, mixed>
     */
    public function sendChatAction(int|string $chatId, string $action): array
    {
        return $this->call('sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action,
        ]);
    }

    /**
     * Get a list of profile pictures for a user.
     *
     * @param int $userId Target user ID
     * @param int $offset
     * @param int $limit
     * @return array<string, mixed>
     */
    public function getUserProfilePhotos(int $userId, int $offset = 0, int $limit = 100): array
    {
        return $this->call('getUserProfilePhotos', [
            'user_id' => $userId,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    /**
     * Get basic info about a file and prepare it for downloading.
     *
     * @param string $fileId Target File ID
     * @return array<string, mixed> File object
     */
    public function getFile(string $fileId): array
    {
        return $this->call('getFile', ['file_id' => $fileId]);
    }

    /**
     * Ban a user in a group, supergroup, or channel.
     *
     * @param int|string $chatId
     * @param int $userId
     * @param array<string, mixed> $options (until_date, revoke_messages)
     * @return array<string, mixed>
     */
    public function banChatMember(int|string $chatId, int $userId, array $options = []): array
    {
        return $this->call('banChatMember', array_merge([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ], $options));
    }

    /**
     * Unban a previously banned user in a supergroup or channel.
     *
     * @param int|string $chatId
     * @param int $userId
     * @param array<string, mixed> $options (only_if_banned)
     * @return array<string, mixed>
     */
    public function unbanChatMember(int|string $chatId, int $userId, array $options = []): array
    {
        return $this->call('unbanChatMember', array_merge([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ], $options));
    }

    /**
     * Restrict a user in a supergroup.
     *
     * @param int|string $chatId
     * @param int $userId
     * @param array<string, mixed> $permissions ChatPermissions array
     * @param array<string, mixed> $options (until_date, use_independent_chat_permissions)
     * @return array<string, mixed>
     */
    public function restrictChatMember(int|string $chatId, int $userId, array $permissions, array $options = []): array
    {
        return $this->call('restrictChatMember', array_merge([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'permissions' => $permissions,
        ], $options));
    }

    /**
     * Promote or demote a user in a supergroup or channel.
     *
     * @param int|string $chatId
     * @param int $userId
     * @param array<string, mixed> $options (is_anonymous, can_manage_chat, can_post_messages, can_edit_messages, can_delete_messages, etc.)
     * @return array<string, mixed>
     */
    public function promoteChatMember(int|string $chatId, int $userId, array $options = []): array
    {
        return $this->call('promoteChatMember', array_merge([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ], $options));
    }

    /**
     * Generates a new primary invite link for a chat; revoking any previously generated primary link.
     *
     * @param int|string $chatId Target Chat ID
     * @return array<string, mixed>
     */
    public function exportChatInviteLink(int|string $chatId): array
    {
        return $this->call('exportChatInviteLink', ['chat_id' => $chatId]);
    }

    /**
     * Create an additional invite link for a chat.
     *
     * @param int|string $chatId Target Chat ID
     * @param array<string, mixed> $options (name, expire_date, member_limit, creates_join_request)
     * @return array<string, mixed>
     */
    public function createChatInviteLink(int|string $chatId, array $options = []): array
    {
        return $this->call('createChatInviteLink', array_merge(['chat_id' => $chatId], $options));
    }

    /**
     * Edit text and game messages.
     *
     * @param string $text New text of the message
     * @param int|string|null $chatId Target Chat ID (required if inline_message_id is not specified)
     * @param int|null $messageId Identifier of message to edit (required if inline_message_id is not specified)
     * @param string|null $inlineMessageId Identifier of the inline message
     * @param array<string, mixed> $options (parse_mode, entities, reply_markup)
     * @return array<string, mixed>
     */
    public function editMessageText(string $text, int|string|null $chatId = null, ?int $messageId = null, ?string $inlineMessageId = null, array $options = []): array
    {
        $params = array_merge(['text' => $text], $this->normalizeOptions($options));
        if ($chatId !== null) {
            $params['chat_id'] = $chatId;
        }
        if ($messageId !== null) {
            $params['message_id'] = $messageId;
        }
        if ($inlineMessageId !== null) {
            $params['inline_message_id'] = $inlineMessageId;
        }
        return $this->call('editMessageText', $params);
    }

    /**
     * Edit caption of messages.
     *
     * @param string $caption New caption
     * @param int|string|null $chatId
     * @param int|null $messageId
     * @param string|null $inlineMessageId
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function editMessageCaption(string $caption = '', int|string|null $chatId = null, ?int $messageId = null, ?string $inlineMessageId = null, array $options = []): array
    {
        $params = array_merge(['caption' => $caption], $this->normalizeOptions($options));
        if ($chatId !== null) {
            $params['chat_id'] = $chatId;
        }
        if ($messageId !== null) {
            $params['message_id'] = $messageId;
        }
        if ($inlineMessageId !== null) {
            $params['inline_message_id'] = $inlineMessageId;
        }
        return $this->call('editMessageCaption', $params);
    }

    /**
     * Edit only the reply markup of messages.
     *
     * @param array|InlineKeyboard|null $replyMarkup Inline keyboard markup
     * @param int|string|null $chatId
     * @param int|null $messageId
     * @param string|null $inlineMessageId
     * @return array<string, mixed>
     */
    public function editMessageReplyMarkup(array|InlineKeyboard|null $replyMarkup = null, int|string|null $chatId = null, ?int $messageId = null, ?string $inlineMessageId = null): array
    {
        $params = [];
        if ($replyMarkup instanceof InlineKeyboard) {
            $params['reply_markup'] = $replyMarkup->toArray();
        } elseif ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }
        if ($chatId !== null) {
            $params['chat_id'] = $chatId;
        }
        if ($messageId !== null) {
            $params['message_id'] = $messageId;
        }
        if ($inlineMessageId !== null) {
            $params['inline_message_id'] = $inlineMessageId;
        }
        return $this->call('editMessageReplyMarkup', $params);
    }

    /**
     * Delete a single message from a chat.
     *
     * @param int|string $chatId Target Chat ID
     * @param int $messageId Message ID to delete
     * @return array<string, mixed>
     */
    public function deleteMessage(int|string $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * Delete multiple messages simultaneously.
     *
     * @param int|string $chatId Target Chat ID
     * @param list<int> $messageIds Message IDs to delete (1-100)
     * @return array<string, mixed>
     */
    public function deleteMessages(int|string $chatId, array $messageIds): array
    {
        return $this->call('deleteMessages', [
            'chat_id' => $chatId,
            'message_ids' => $messageIds,
        ]);
    }

    /**
     * Send answers to callback queries sent from inline keyboards.
     *
     * @param string $callbackQueryId Unique query ID
     * @param string|null $text Text of notification (0-200 chars)
     * @param bool $showAlert If true, show alert dialog instead of notification bar
     * @param array<string, mixed> $options (url, cache_time)
     * @return array<string, mixed>
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false, array $options = []): array
    {
        $params = array_merge([
            'callback_query_id' => $callbackQueryId,
            'show_alert' => $showAlert,
        ], $options);

        if ($text !== null) {
            $params['text'] = $text;
        }

        return $this->call('answerCallbackQuery', $params);
    }

    /**
     * Change the list of the bot's commands.
     *
     * @param list<array{command: string, description: string}> $commands
     * @param array<string, mixed> $options (scope, language_code)
     * @return array<string, mixed>
     */
    public function setMyCommands(array $commands, array $options = []): array
    {
        return $this->call('setMyCommands', array_merge([
            'commands' => $commands,
        ], $options));
    }

    /**
     * Get the current list of the bot's commands for the given scope and user language.
     *
     * @param array<string, mixed> $options (scope, language_code)
     * @return array<string, mixed>
     */
    public function getMyCommands(array $options = []): array
    {
        return $this->call('getMyCommands', $options);
    }

    /**
     * Specify a URL and receive incoming updates via an outgoing webhook.
     *
     * @param string $url HTTPS URL to send updates to
     * @param array<string, mixed> $options (ip_address, max_connections, allowed_updates, drop_pending_updates, secret_token)
     * @return array<string, mixed>
     */
    public function setWebhook(string $url, array $options = []): array
    {
        return $this->call('setWebhook', array_merge(['url' => $url], $options));
    }

    /**
     * Remove webhook integration.
     *
     * @param bool $dropPendingUpdates Pass True to drop all pending updates
     * @return array<string, mixed>
     */
    public function deleteWebhook(bool $dropPendingUpdates = false): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => $dropPendingUpdates]);
    }

    /**
     * Get current webhook status.
     *
     * @return array<string, mixed> WebhookInfo object
     */
    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    /**
     * Helper to auto-serialize InlineKeyboard instances in options.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function normalizeOptions(array $options): array
    {
        if (isset($options['reply_markup']) && $options['reply_markup'] instanceof InlineKeyboard) {
            $options['reply_markup'] = $options['reply_markup']->toArray();
        }
        return $options;
    }
}
