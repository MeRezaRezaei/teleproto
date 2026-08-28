<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Types;

/**
 * Convenient Type Helpers to construct Telegram MTProto InputChannel structures.
 */
class InputChannel
{
    /**
     * Constructs an InputChannel structure.
     *
     * @param int $channelId Target Channel / Supergroup ID
     * @param int|string $accessHash Access hash from contact/dialog/peer resolution
     * @return array{_: 'inputChannel', channel_id: int, access_hash: int}
     */
    public static function channel(int $channelId, int|string $accessHash = 0): array
    {
        return [
            '_' => 'inputChannel',
            'channel_id' => $channelId,
            'access_hash' => (int)$accessHash,
        ];
    }

    /**
     * Constructs an InputChannelEmpty structure.
     *
     * @return array{_: 'inputChannelEmpty'}
     */
    public static function empty(): array
    {
        return [
            '_' => 'inputChannelEmpty',
        ];
    }

    /**
     * Constructs an InputChannelFromMessage structure to refer to a channel in a message.
     *
     * @param array<string, mixed>|int|string $peer Chat where the message was sent
     * @param int $msgId Message ID
     * @param int $channelId Target Channel ID
     * @return array{_: 'inputChannelFromMessage', peer: mixed, msg_id: int, channel_id: int}
     */
    public static function fromMessage(mixed $peer, int $msgId, int $channelId): array
    {
        return [
            '_' => 'inputChannelFromMessage',
            'peer' => $peer,
            'msg_id' => $msgId,
            'channel_id' => $channelId,
        ];
    }
}
