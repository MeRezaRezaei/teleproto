<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * PHONE_NUMBER_* errors from auth.sendCode — descriptions verbatim from
 * https://core.telegram.org/method/auth.sendCode#possible-errors
 */
class PhoneNumberException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage, int $rpcErrorCode)
    {
        $hint = match ($rpcErrorMessage) {
            'PHONE_NUMBER_BANNED' => 'The provided phone number is banned from telegram.',
            'PHONE_NUMBER_UNOCCUPIED' => 'The phone number is not yet being used. → Complete signup (auth.signUp) or use another number.',
            'PHONE_NUMBER_FLOOD' => 'You asked for the code too many times. → Wait before requesting another code.',
            'PHONE_NUMBER_INVALID' => 'The phone number is invalid. → Use full international format, e.g. +989123456789.',
            default => 'Phone number rejected by auth.sendCode.',
        };
        parent::__construct($rpcErrorMessage, $rpcErrorCode, $hint);
    }
}
