<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Types;

/**
 * Convenient Type Helpers to construct Telegram MTProto InputUser structures.
 */
class InputUser
{
    /**
     * Constructs an InputUser structure.
     *
     * @param int $userId Target User ID
     * @param int|string $accessHash Access hash from contact/dialog/peer resolution
     * @return array{_: 'inputUser', user_id: int, access_hash: string}
     */
    public static function user(int $userId, int|string $accessHash = 0): array
    {
        return [
            '_' => 'inputUser',
            'user_id' => $userId,
            'access_hash' => (string)$accessHash,
        ];
    }

    /**
     * Constructs an InputUserSelf structure representing current account.
     *
     * @return array{_: 'inputUserSelf'}
     */
    public static function self(): array
    {
        return [
            '_' => 'inputUserSelf',
        ];
    }

    /**
     * Constructs an InputUserEmpty structure.
     *
     * @return array{_: 'inputUserEmpty'}
     */
    public static function empty(): array
    {
        return [
            '_' => 'inputUserEmpty',
        ];
    }

    /**
     * Constructs an InputUserFromMessage structure to refer to a user in a message.
     *
     * @param array<string, mixed>|int|string $peer Chat where the message was sent
     * @param int $msgId Message ID
     * @param int $userId Target User ID
     * @return array{_: 'inputUserFromMessage', peer: mixed, msg_id: int, user_id: int}
     */
    public static function fromMessage(mixed $peer, int $msgId, int $userId): array
    {
        return [
            '_' => 'inputUserFromMessage',
            'peer' => $peer,
            'msg_id' => $msgId,
            'user_id' => $userId,
        ];
    }
}
