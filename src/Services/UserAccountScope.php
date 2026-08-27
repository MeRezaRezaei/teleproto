<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use MeRezaRezaei\Teleproto\MTProto\Client as MTProtoClient;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Types\InputPeer;

/**
 * Scoped User MTProto operations with typed helper methods and full PHPDoc method mappings.
 *
 * @see \MeRezaRezaei\Teleproto\Types\InputPeer
 * @see \MeRezaRezaei\Teleproto\Types\InputMedia
 * @see \MeRezaRezaei\Teleproto\Entities\EntityParser
 * @see \MeRezaRezaei\Teleproto\Media\StorageMedia
 */
class UserAccountScope
{
    public function __construct(
        public MTProtoClient $mtproto,
        public SessionData $session
    ) {}

    /**
     * Executes any raw Telegram MTProto method directly (Layer 227+).
     *
     * @param string $method MTProto method name (e.g. 'messages.sendMessage', 'users.getFullUser')
     * @param array<string, mixed> $params Parameter payload matching Telegram schema
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        return $this->mtproto->call($method, $params);
    }

    /**
     * Sends a text message to a user, group, or channel.
     *
     * @param int|string|array<string, mixed> $peer Target username (@user), peer ID, or InputPeer array
     * @param string $text Message content
     * @param array<string, mixed> $options Optional settings (entities, reply_to_msg_id, schedule_date)
     * @return array<string, mixed> Updates or Sent Message object
     *
     * @see \MeRezaRezaei\Teleproto\Types\InputPeer
     * @see \MeRezaRezaei\Teleproto\Entities\EntityParser
     */
    public function sendMessage(int|string|array $peer, string $text, array $options = []): array
    {
        return $this->call('messages.sendMessage', array_merge([
            'peer' => $this->normalizePeer($peer),
            'message' => $text,
            'random_id' => random_int(1, PHP_INT_MAX),
        ], $options));
    }

    /**
     * Sends media (photo, document, video, etc.) to a chat or channel.
     *
     * @param int|string|array<string, mixed> $peer Target chat or channel
     * @param array<string, mixed> $media InputMedia array (e.g. inputMediaUploadedPhoto, inputMediaUploadedDocument)
     * @param string $message Optional caption text
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     *
     * @see \MeRezaRezaei\Teleproto\Types\InputPeer
     * @see \MeRezaRezaei\Teleproto\Types\InputMedia
     */
    public function sendMedia(int|string|array $peer, array $media, string $message = '', array $options = []): array
    {
        return $this->call('messages.sendMedia', array_merge([
            'peer' => $this->normalizePeer($peer),
            'media' => $media,
            'message' => $message,
            'random_id' => random_int(1, PHP_INT_MAX),
        ], $options));
    }

    /**
     * Retrieves chat message history.
     *
     * @param int|string|array<string, mixed> $peer Target chat or channel
     * @param int $limit Number of messages to retrieve (default: 50, max: 100)
     * @param int $offsetId Offset message ID for pagination (0 = latest)
     * @return array<string, mixed> messages.messagesSlice or messages.channelMessages
     *
     * @see \MeRezaRezaei\Teleproto\Types\InputPeer
     */
    public function getHistory(int|string|array $peer, int $limit = 50, int $offsetId = 0): array
    {
        return $this->call('messages.getHistory', [
            'peer' => $this->normalizePeer($peer),
            'offset_id' => $offsetId,
            'offset_date' => 0,
            'add_offset' => 0,
            'limit' => $limit,
            'max_id' => 0,
            'min_id' => 0,
            'hash' => 0,
        ]);
    }

    /**
     * Search for messages within a specific chat or channel.
     *
     * @param int|string|array<string, mixed> $peer Target chat
     * @param string $query Text query
     * @param int $limit Number of results
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function searchMessages(int|string|array $peer, string $query, int $limit = 50, array $options = []): array
    {
        return $this->call('messages.search', array_merge([
            'peer' => $this->normalizePeer($peer),
            'q' => $query,
            'filter' => ['_' => 'inputMessagesFilterEmpty'],
            'min_date' => 0,
            'max_date' => 0,
            'offset_id' => 0,
            'add_offset' => 0,
            'limit' => $limit,
            'max_id' => 0,
            'min_id' => 0,
            'hash' => 0,
        ], $options));
    }

    /**
     * Marks messages as read in a chat or channel.
     *
     * @param int|string|array<string, mixed> $peer Target chat
     * @param int $maxId All message IDs up to this ID are marked as read (0 = all)
     * @return array<string, mixed>
     */
    public function readHistory(int|string|array $peer, int $maxId = 0): array
    {
        return $this->call('messages.readHistory', [
            'peer' => $this->normalizePeer($peer),
            'max_id' => $maxId,
        ]);
    }

    /**
     * Retrieves the user's dialog list (active chats).
     *
     * @param int $limit Number of dialogs to fetch (default: 50)
     * @param int $offsetDate Offset timestamp for pagination
     * @return array<string, mixed>
     */
    public function getDialogs(int $limit = 50, int $offsetDate = 0): array
    {
        return $this->call('messages.getDialogs', [
            'offset_date' => $offsetDate,
            'offset_id' => 0,
            'offset_peer' => ['_' => 'inputPeerEmpty'],
            'limit' => $limit,
            'hash' => 0,
        ]);
    }

    /**
     * Retrieves full user profile information.
     *
     * @param int|array<string, mixed> $user User ID or InputUser array
     * @return array<string, mixed> users.userFull
     *
     * @see \MeRezaRezaei\Teleproto\Types\InputPeer
     */
    public function getFullUser(int|array $user): array
    {
        $inputUser = is_int($user)
            ? ['_' => 'inputUser', 'user_id' => $user, 'access_hash' => 0]
            : $user;

        return $this->call('users.getFullUser', ['id' => $inputUser]);
    }

    /**
     * Retrieves full channel or supergroup information.
     *
     * @param int|array<string, mixed> $channel Channel ID or InputChannel array
     * @return array<string, mixed> messages.chatFull
     *
     * @see \MeRezaRezaei\Teleproto\Types\InputPeer
     */
    public function getFullChannel(int|array $channel): array
    {
        $inputChannel = is_int($channel)
            ? ['_' => 'inputChannel', 'channel_id' => $channel, 'access_hash' => 0]
            : $channel;

        return $this->call('channels.getFullChannel', ['channel' => $inputChannel]);
    }

    /**
     * Joins a public or private channel/supergroup.
     *
     * @param int|string|array<string, mixed> $channel Target channel ID or InputChannel
     * @return array<string, mixed> Updates object
     */
    public function joinChannel(int|string|array $channel): array
    {
        $inputChannel = is_int($channel)
            ? ['_' => 'inputChannel', 'channel_id' => $channel, 'access_hash' => 0]
            : $channel;

        return $this->call('channels.joinChannel', ['channel' => $inputChannel]);
    }

    /**
     * Leaves a channel or supergroup.
     *
     * @param int|string|array<string, mixed> $channel Target channel ID or InputChannel
     * @return array<string, mixed> Updates object
     */
    public function leaveChannel(int|string|array $channel): array
    {
        $inputChannel = is_int($channel)
            ? ['_' => 'inputChannel', 'channel_id' => $channel, 'access_hash' => 0]
            : $channel;

        return $this->call('channels.leaveChannel', ['channel' => $inputChannel]);
    }

    /**
     * Forwards one or more messages to another chat.
     *
     * @param int|string|array<string, mixed> $fromPeer Source chat/channel
     * @param int|string|array<string, mixed> $toPeer Destination chat/channel
     * @param list<int> $messageIds Message IDs to forward
     * @return array<string, mixed>
     */
    public function forwardMessages(int|string|array $fromPeer, int|string|array $toPeer, array $messageIds): array
    {
        return $this->call('messages.forwardMessages', [
            'from_peer' => $this->normalizePeer($fromPeer),
            'to_peer' => $this->normalizePeer($toPeer),
            'id' => $messageIds,
            'random_id' => array_map(fn() => random_int(1, PHP_INT_MAX), $messageIds),
        ]);
    }

    /**
     * Deletes messages from a chat.
     *
     * @param list<int> $messageIds
     * @param bool $revoke Whether to delete for everyone (true) or just self (false)
     * @return array<string, mixed>
     */
    public function deleteMessages(array $messageIds, bool $revoke = true): array
    {
        return $this->call('messages.deleteMessages', [
            'id' => $messageIds,
            'revoke' => $revoke,
        ]);
    }

    /**
     * Normalize peer representation to standard MTProto peer input.
     *
     * @param int|string|array<string, mixed> $peer
     * @return int|string|array<string, mixed>
     */
    protected function normalizePeer(int|string|array $peer): int|string|array
    {
        return $peer;
    }
}
