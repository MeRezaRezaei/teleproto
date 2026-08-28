<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * PHONE_CODE_* errors from auth.signIn — descriptions verbatim from
 * https://core.telegram.org/method/auth.signIn#possible-errors
 */
class PhoneCodeException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage, int $rpcErrorCode, ?string $method = null)
    {
        $hint = match ($rpcErrorMessage) {
            'PHONE_CODE_EXPIRED' => 'The phone code you provided has expired. → Request a new code (auth.resendCode).',
            'PHONE_CODE_EMPTY' => 'phone_code is missing. → Ask the user for the login code and send it in phone_code.',
            default => 'The provided phone code is invalid. → Re-check the digits the user entered.',
        };
        parent::__construct($rpcErrorMessage, $rpcErrorCode, $hint, $method);
    }
}
