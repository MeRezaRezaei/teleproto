<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * API_ID_INVALID / API_ID_PUBLISHED_FLOOD — the api_id/api_hash pair is
 * wrong or was published and abused.
 *
 * @see https://core.telegram.org/api/obtaining_api_id
 */
class ApiIdException extends RpcErrorException
{
    public function __construct(string $rpcErrorMessage, int $rpcErrorCode)
    {
        $hint = $rpcErrorMessage === 'API_ID_PUBLISHED_FLOOD'
            ? 'This api_id/api_hash pair was published and is flood-limited: obtain your own from my.telegram.org.'
            : 'api_id/api_hash pair rejected: check the values from https://my.telegram.org.';
        parent::__construct($rpcErrorMessage, $rpcErrorCode, $hint);
    }
}
