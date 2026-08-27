<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use MeRezaRezaei\Teleproto\MTProto\Client as MTProtoClient;
use MeRezaRezaei\Teleproto\MTProto\SessionData;

/**
 * Scoped User MTProto operations with typed helper methods and full PHPDoc method mappings.
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
     * @param int|string|array $peer Target username (@user), peer ID, or InputPeer array
     * @param string $text Message content
     * @param array<string, mixed> $options Optional settings (entities, reply_to_msg_id, schedule_date)
     * @return array<string, mixed>
     */
    public function sendMessage(int|string|array $peer, string $text, array $options = []): array
    {
        return $this->call('messages.sendMessage', array_merge([
            'peer' => $peer,
            'message' => $text,
            'random_id' => random_int(1, PHP_INT_MAX),
        ], $options));
    }

    /**
     * Retrieves chat message history.
     *
     * @param int|string|array $peer Target chat or channel
     * @param int $limit Number of messages to retrieve (default: 50, max: 100)
     * @param int $offsetId Offset message ID for pagination (0 = latest)
     * @return array<string, mixed>
     */
    public function getHistory(int|string|array $peer, int $limit = 50, int $offsetId = 0): array
    {
        return $this->call('messages.getHistory', [
            'peer' => $peer,
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
     * @param int|array $user User ID or InputUser array
     * @return array<string, mixed>
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
     * @param int|array $channel Channel ID or InputChannel array
     * @return array<string, mixed>
     */
    public function getFullChannel(int|array $channel): array
    {
        $inputChannel = is_int($channel)
            ? ['_' => 'inputChannel', 'channel_id' => $channel, 'access_hash' => 0]
            : $channel;

        return $this->call('channels.getFullChannel', ['channel' => $inputChannel]);
    }

    /**
     * Forwards one or more messages to another chat.
     *
     * @param int|string|array $fromPeer Source chat/channel
     * @param int|string|array $toPeer Destination chat/channel
     * @param list<int> $messageIds Message IDs to forward
     * @return array<string, mixed>
     */
    public function forwardMessages(int|string|array $fromPeer, int|string|array $toPeer, array $messageIds): array
    {
        return $this->call('messages.forwardMessages', [
            'from_peer' => $fromPeer,
            'to_peer' => $toPeer,
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
}
