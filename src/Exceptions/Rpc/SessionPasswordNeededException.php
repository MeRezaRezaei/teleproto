<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * SESSION_PASSWORD_NEEDED — per https://core.telegram.org/api/auth#2fa:
 * if 2FA is enabled, sign-in returns this; complete the SRP flow
 * (account.getPassword → auth.checkPassword).
 */
class SessionPasswordNeededException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage = 'SESSION_PASSWORD_NEEDED', int $rpcErrorCode = 401)
    {
        parent::__construct($rpcErrorMessage, $rpcErrorCode, '2FA is enabled on this account. → Fetch account.getPassword and complete the SRP flow (auth.checkPassword).');
    }
}
