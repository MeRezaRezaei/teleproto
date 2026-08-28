<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

use MeRezaRezaei\Teleproto\Exceptions\TelegramException;

/**
 * Base class for Telegram RPC errors (rpc_error) with the raw TL error
 * message, the numeric code, and a documentation-backed hint explaining
 * what happened and what to do next.
 */
class RpcErrorException extends TelegramException
{
    public function __construct(
        public readonly string $rpcErrorMessage,
        public readonly int $rpcErrorCode,
        public readonly string $docHint = '',
        public readonly ?string $rpcMethod = null
    ) {
        $methodPart = $rpcMethod !== null ? " during {$rpcMethod}" : '';
        parent::__construct(
            $docHint !== ''
                ? sprintf('MTProto RPC error %s (code %d)%s: %s', $rpcErrorMessage, $rpcErrorCode, $methodPart, $docHint)
                : sprintf('MTProto RPC error %s (code %d)%s', $rpcErrorMessage, $rpcErrorCode, $methodPart),
            $rpcErrorCode
        );
    }
}
