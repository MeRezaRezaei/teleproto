<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * SESSION_PASSWORD_NEEDED — 2FA cloud password is enabled on this account.
 * Fetch account.getPassword and complete the SRP flow (auth.checkPassword).
 *
 * @see https://core.telegram.org/api/auth#2fa
 */
class SessionPasswordNeededException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage = 'SESSION_PASSWORD_NEEDED', int $rpcErrorCode = 401)
    {
        parent::__construct($rpcErrorMessage, $rpcErrorCode, '2FA cloud password required: run the SRP (auth.checkPassword) flow.');
    }
}
