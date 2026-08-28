<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use MeRezaRezaei\Teleproto\MTProto\Client as MTProtoClient;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Types\InputChannel;
use MeRezaRezaei\Teleproto\Types\InputUser;

/**
 * Scoped User MTProto operations with typed helper methods and full PHPDoc method mappings.
 *
 * @see \MeRezaRezaei\Teleproto\Types\InputPeer
 * @see \MeRezaRezaei\Teleproto\Types\InputUser
 * @see \MeRezaRezaei\Teleproto\Types\InputChannel
 * @see \MeRezaRezaei\Teleproto\Types\InputContact
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
     * Always returns the live session from the underlying MTProto client.
     */
    public function getSession(): SessionData
    {
        return $this->mtproto->session ?? $this->session;
    }

    /**
     * Magic getter to ensure $scope->session always returns the active live SessionData.
     */
    public function __get(string $name): mixed
    {
        if ($name === 'session') {
            return $this->mtproto->session ?? $this->session;
        }
        return null;
    }

    /**
     * Executes any raw Telegram MTProto method directly (Layer 227+).
     *
     * @param string $method MTProto method name (e.g. 'messages.sendMessage', 'users.getFullUser')
     * @param array<string, mixed> $params Parameter payload matching Telegram schema
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $res = $this->mtproto->call($method, $params);
        if ($this->mtproto->session !== null) {
            $this->session = $this->mtproto->session;
        }
        return $res;
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
     * Sends a reaction (emoji) to a message.
     *
     * @param int|string|array<string, mixed> $peer Target chat
     * @param int $msgId Target message ID
     * @param list<array<string, mixed>>|string $reaction Emoji string (e.g. '👍') or reaction array
     * @param array<string, mixed> $options
     * @return array<string, mixed> Updates object
     */
    public function sendReaction(int|string|array $peer, int $msgId, array|string $reaction = '👍', array $options = []): array
    {
        $reactionList = is_string($reaction)
            ? [['_' => 'reactionEmoji', 'emoticon' => $reaction]]
            : $reaction;

        return $this->call('messages.sendReaction', array_merge([
            'peer' => $this->normalizePeer($peer),
            'msg_id' => $msgId,
            'reaction' => $reactionList,
        ], $options));
    }

    /**
     * Pins a message in a chat or channel.
     *
     * @param int|string|array<string, mixed> $peer Target chat
     * @param int $msgId Message ID to pin
     * @param bool $silent Pin silently without notifying users
     * @param bool $pmOneSide Pin only for self in private chat
     * @return array<string, mixed>
     */
    public function pinMessage(int|string|array $peer, int $msgId, bool $silent = false, bool $pmOneSide = false): array
    {
        return $this->call('messages.updatePinnedMessage', [
            'peer' => $this->normalizePeer($peer),
            'id' => $msgId,
            'silent' => $silent,
            'pm_oneside' => $pmOneSide,
        ]);
    }

    /**
     * Unpins all messages in a chat or channel.
     *
     * @param int|string|array<string, mixed> $peer Target chat
     * @return array<string, mixed>
     */
    public function unpinAllMessages(int|string|array $peer): array
    {
        return $this->call('messages.unpinAllMessages', [
            'peer' => $this->normalizePeer($peer),
        ]);
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
     * @see \MeRezaRezaei\Teleproto\Types\InputUser
     */
    public function getFullUser(int|array $user): array
    {
        $inputUser = is_int($user)
            ? InputUser::user($user)
            : $user;

        return $this->call('users.getFullUser', ['id' => $inputUser]);
    }

    /**
     * Retrieves full channel or supergroup information.
     *
     * @param int|array<string, mixed> $channel Channel ID or InputChannel array
     * @return array<string, mixed> messages.chatFull
     *
     * @see \MeRezaRezaei\Teleproto\Types\InputChannel
     */
    public function getFullChannel(int|array $channel): array
    {
        $inputChannel = is_int($channel)
            ? InputChannel::channel($channel)
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
            ? InputChannel::channel($channel)
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
            ? InputChannel::channel($channel)
            : $channel;

        return $this->call('channels.leaveChannel', ['channel' => $inputChannel]);
    }

    /**
     * Creates a new Channel or Supergroup.
     *
     * @param string $title Channel title
     * @param string $about Channel description
     * @param bool $megagroup True to create a supergroup/gigagroup, False for broadcast channel
     * @param bool $forImport True if importing chat history
     * @return array<string, mixed> Updates object
     */
    public function createChannel(string $title, string $about = '', bool $megagroup = false, bool $forImport = false): array
    {
        return $this->call('channels.createChannel', [
            'broadcast' => !$megagroup,
            'megagroup' => $megagroup,
            'for_import' => $forImport,
            'title' => $title,
            'about' => $about,
        ]);
    }

    /**
     * Invites users to a channel or supergroup.
     *
     * @param int|string|array<string, mixed> $channel Target channel
     * @param list<array<string, mixed>|int> $users Array of User IDs or InputUser objects
     * @return array<string, mixed> Updates object
     */
    public function inviteToChannel(int|string|array $channel, array $users): array
    {
        $inputUsers = array_map(function ($u) {
            return is_int($u) ? InputUser::user($u) : $u;
        }, $users);

        $inputChannel = is_int($channel)
            ? InputChannel::channel($channel)
            : $channel;

        return $this->call('channels.inviteToChannel', [
            'channel' => $inputChannel,
            'users' => $inputUsers,
        ]);
    }

    /**
     * Retrieves participants (members, admins, banned, etc.) in a channel or supergroup.
     *
     * @param int|string|array<string, mixed> $channel
     * @param array<string, mixed> $filter ChannelParticipantsFilter (e.g. channelParticipantsRecent, channelParticipantsAdmins)
     * @param int $offset
     * @param int $limit
     * @return array<string, mixed> channels.channelParticipants
     */
    public function getParticipants(int|string|array $channel, array $filter = [], int $offset = 0, int $limit = 50): array
    {
        $inputChannel = is_int($channel)
            ? InputChannel::channel($channel)
            : $channel;

        return $this->call('channels.getParticipants', [
            'channel' => $inputChannel,
            'filter' => empty($filter) ? ['_' => 'channelParticipantsRecent'] : $filter,
            'offset' => $offset,
            'limit' => $limit,
            'hash' => 0,
        ]);
    }

    /**
     * Retrieves address book contacts with Telegram accounts.
     *
     * @param int $hash Hash of current contact list
     * @return array<string, mixed> contacts.contacts
     */
    public function getContacts(int $hash = 0): array
    {
        return $this->call('contacts.getContacts', ['hash' => $hash]);
    }

    /**
     * Imports contacts into the user's address book.
     *
     * @param list<array<string, mixed>> $contacts Array of InputContact objects
     * @return array<string, mixed> contacts.importedContacts
     *
     * @see \MeRezaRezaei\Teleproto\Types\InputContact
     */
    public function importContacts(array $contacts): array
    {
        return $this->call('contacts.importContacts', [
            'contacts' => $contacts,
        ]);
    }

    /**
     * Deletes contacts from the address book.
     *
     * @param list<array<string, mixed>|int> $userIds Array of User IDs or InputUser objects
     * @return array<string, mixed>
     */
    public function deleteContacts(array $userIds): array
    {
        $inputUsers = array_map(function ($u) {
            return is_int($u) ? InputUser::user($u) : $u;
        }, $userIds);

        return $this->call('contacts.deleteContacts', [
            'id' => $inputUsers,
        ]);
    }

    /**
     * Searches contacts and global Telegram users by query.
     *
     * @param string $query
     * @param int $limit
     * @return array<string, mixed> contacts.found
     */
    public function searchContacts(string $query, int $limit = 50): array
    {
        return $this->call('contacts.search', [
            'q' => $query,
            'limit' => $limit,
        ]);
    }

    /**
     * Updates user's first name, last name, and bio (about).
     *
     * @param string|null $firstName
     * @param string|null $lastName
     * @param string|null $about
     * @return array<string, mixed> User object
     */
    public function updateProfile(?string $firstName = null, ?string $lastName = null, ?string $about = null): array
    {
        $params = [];
        if ($firstName !== null) {
            $params['first_name'] = $firstName;
        }
        if ($lastName !== null) {
            $params['last_name'] = $lastName;
        }
        if ($about !== null) {
            $params['about'] = $about;
        }

        return $this->call('account.updateProfile', $params);
    }

    /**
     * Changes user's @username.
     *
     * @param string $username New username without @
     * @return array<string, mixed>
     */
    public function updateUsername(string $username): array
    {
        return $this->call('account.updateUsername', ['username' => $username]);
    }

    /**
     * Checks if a username is available.
     *
     * @param string $username
     * @return array<string, mixed>
     */
    public function checkUsername(string $username): array
    {
        return $this->call('account.checkUsername', ['username' => $username]);
    }

    /**
     * Updates online/offline presence status.
     *
     * @param bool $offline If true, set offline; if false, set online
     * @return array<string, mixed>
     */
    public function updateStatus(bool $offline = false): array
    {
        return $this->call('account.updateStatus', ['offline' => $offline]);
    }

    /**
     * Retrieves all active authorization sessions / logged-in devices.
     *
     * @return array<string, mixed> account.authorizations
     */
    public function getAuthorizations(): array
    {
        return $this->call('account.getAuthorizations');
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
