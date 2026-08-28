<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * AUTH_KEY_UNREGISTERED / AUTH_KEY_INVALID / SESSION_REVOKED /
 * SESSION_EXPIRED — the authorization key is not usable.
 */
class AuthKeyException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage = 'AUTH_KEY_UNREGISTERED', int $rpcErrorCode = 401)
    {
        $hint = match ($rpcErrorMessage) {
            'AUTH_KEY_INVALID' => 'auth_key_id is not known to the server: regenerate the key (fresh handshake).',
            'SESSION_REVOKED' => 'This session was revoked by the user (Devices → terminate): log in again.',
            'SESSION_EXPIRED' => 'Session expired: log in again.',
            default => 'Key not registered: a freshly generated key must complete its first encrypted request on the handshake connection; otherwise re-authenticate.',
        };
        parent::__construct($rpcErrorMessage, $rpcErrorCode, $hint);
    }
}
