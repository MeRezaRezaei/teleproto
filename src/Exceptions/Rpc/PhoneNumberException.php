<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * PHONE_NUMBER_INVALID / PHONE_NUMBER_BANNED / PHONE_NUMBER_UNOCCUPIED.
 */
class PhoneNumberException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage, int $rpcErrorCode)
    {
        $hint = match ($rpcErrorMessage) {
            'PHONE_NUMBER_BANNED' => 'This number is banned from Telegram — nothing a client can do.',
            'PHONE_NUMBER_UNOCCUPIED' => 'Number has no Telegram account: complete signup (auth.signUp) or use another number.',
            default => 'Phone number format not accepted: use full international format (e.g. +989123456789).',
        };
        parent::__construct($rpcErrorMessage, $rpcErrorCode, $hint);
    }
}
