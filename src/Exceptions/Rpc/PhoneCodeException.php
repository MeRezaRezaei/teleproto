<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * PHONE_CODE_INVALID / PHONE_CODE_EXPIRED / PHONE_CODE_EMPTY — the login
 * code was wrong, expired, or missing. Resend via auth.resendCode or
 * restart auth.sendCode.
 *
 * @see https://core.telegram.org/api/auth
 */
class PhoneCodeException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage, int $rpcErrorCode)
    {
        $hint = match ($rpcErrorMessage) {
            'PHONE_CODE_EXPIRED' => 'Login code expired: request a new one (auth.resendCode / auth.sendCode).',
            'PHONE_CODE_EMPTY' => 'No code supplied: ask the user for the login code.',
            default => 'Login code rejected: re-check the digits, or resend the code.',
        };
        parent::__construct($rpcErrorMessage, $rpcErrorCode, $hint);
    }
}
