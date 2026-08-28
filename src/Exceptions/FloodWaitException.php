<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions;

use MeRezaRezaei\Teleproto\Exceptions\Rpc\FloodWaitException as RpcFloodWaitException;

/**
 * Backward-compatible alias of the docs-backed Rpc\FloodWaitException
 * (FLOOD_WAIT_X — wait $seconds before retrying, per core.telegram.org/api/errors).
 *
 * @deprecated use \MeRezaRezaei\Teleproto\Exceptions\Rpc\FloodWaitException
 * @see \MeRezaRezaei\Teleproto\Exceptions\Rpc\FloodWaitException
 */
class FloodWaitException extends RpcFloodWaitException
{
    public function __construct(int $seconds, string $message = 'FLOOD_WAIT', int $code = 420, ?\Throwable $previous = null)
    {
        parent::__construct($seconds, $message !== '' ? $message : 'FLOOD_WAIT_' . $seconds, $code);
        unset($previous);
    }

    public function getSeconds(): int
    {
        return $this->seconds;
    }
}
