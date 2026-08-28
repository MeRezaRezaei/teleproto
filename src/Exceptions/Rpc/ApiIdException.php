<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * API_ID_* errors — descriptions verbatim from
 * https://core.telegram.org/method/auth.sendCode#possible-errors
 */
class ApiIdException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage, int $rpcErrorCode)
    {
        $hint = $rpcErrorMessage === 'API_ID_PUBLISHED_FLOOD'
            ? "This API id was published somewhere, you can't use it now. → Obtain your own api_id from my.telegram.org."
            : 'API ID invalid. → Check the api_id/api_hash pair from my.telegram.org.';
        parent::__construct($rpcErrorMessage, $rpcErrorCode, $hint);
    }
}
