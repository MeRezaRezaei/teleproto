<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions;

class FloodWaitException extends TelegramException
{
    public function __construct(
        public int $seconds,
        string $message = '',
        int $code = 420,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: "Telegram rate limit exceeded: FLOOD_WAIT_{$seconds}",
            $code,
            $previous
        );
    }

    public function getSeconds(): int
    {
        return $this->seconds;
    }
}
