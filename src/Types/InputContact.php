<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Types;

/**
 * Convenient Type Helpers to construct Telegram MTProto InputContact structures.
 */
class InputContact
{
    /**
     * Constructs an inputPhoneContact structure for importing address book contacts.
     *
     * @param string $phone Phone number in international format (+1234567890)
     * @param string $firstName Contact's first name
     * @param string $lastName Contact's last name
     * @param int $clientId Client-assigned unique 64-bit ID to track imported contact
     * @return array{_: 'inputPhoneContact', client_id: int, phone: string, first_name: string, last_name: string}
     */
    public static function phone(string $phone, string $firstName, string $lastName = '', int $clientId = 0): array
    {
        return [
            '_' => 'inputPhoneContact',
            'client_id' => $clientId !== 0 ? $clientId : random_int(1, PHP_INT_MAX),
            'phone' => $phone,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    }
}
