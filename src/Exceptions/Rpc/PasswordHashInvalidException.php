<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * PASSWORD_HASH_INVALID — description verbatim from
 * https://core.telegram.org/method/auth.checkPassword#possible-errors
 */
class PasswordHashInvalidException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage = 'PASSWORD_HASH_INVALID', int $rpcErrorCode = 400)
    {
        parent::__construct($rpcErrorMessage, $rpcErrorCode, 'The provided password hash is invalid. → Wrong 2FA password; re-ask the user, do not blind-retry.');
    }
}
