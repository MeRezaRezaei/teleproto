<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Types;

/**
 * Convenient Type Helpers to construct Telegram MTProto Input structures easily.
 */
class InputPeer
{
    /**
     * Constructs an InputPeerUser structure.
     * Requires access_hash received from previous dialog/contact/user API calls.
     */
    public static function user(int $userId, int|string $accessHash = 0): array
    {
        return [
            '_' => 'inputPeerUser',
            'user_id' => $userId,
            'access_hash' => (string)$accessHash,
        ];
    }

    /**
     * Constructs an InputPeerChannel structure for Channels & Supergroups.
     * Requires access_hash received from previous dialogs/channels API calls.
     */
    public static function channel(int $channelId, int|string $accessHash = 0): array
    {
        return [
            '_' => 'inputPeerChannel',
            'channel_id' => $channelId,
            'access_hash' => (string)$accessHash,
        ];
    }

    /**
     * Constructs an InputPeerChat structure for basic group chats.
     */
    public static function chat(int $chatId): array
    {
        return [
            '_' => 'inputPeerChat',
            'chat_id' => $chatId,
        ];
    }

    /**
     * Constructs an InputPeerSelf structure representing the currently logged-in account (Saved Messages).
     */
    public static function self(): array
    {
        return [
            '_' => 'inputPeerSelf',
        ];
    }
}
