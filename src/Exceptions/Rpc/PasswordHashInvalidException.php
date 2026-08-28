<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * PASSWORD_HASH_INVALID — the SRP proof did not verify: wrong password
 * (or corrupted SRP parameters). Re-ask the user; do not blind-retry.
 *
 * @see https://core.telegram.org/api/auth#2fa
 */
class PasswordHashInvalidException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage = 'PASSWORD_HASH_INVALID', int $rpcErrorCode = 400)
    {
        parent::__construct($rpcErrorMessage, $rpcErrorCode, 'Wrong 2FA password (SRP proof rejected). Ask the user again.');
    }
}
