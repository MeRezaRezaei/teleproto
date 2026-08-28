<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * FLOOD_WAIT_X — Telegram flood protection kicked in.
 * Wait $seconds before retrying the same request.
 *
 * @see https://core.telegram.org/api/errors#flood-wait-errors
 */
class FloodWaitException extends RpcErrorException
{
    public function __construct(
        public readonly int $seconds,
        string $rpcErrorMessage,
        int $rpcErrorCode
    ) {
        parent::__construct(
            $rpcErrorMessage,
            $rpcErrorCode,
            "Flood protection: wait {$seconds} second(s) before retrying this request."
        );
    }
}
