<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions;

class DcMigrationException extends TelegramException
{
    public function __construct(
        public int $dcId,
        string $message = '',
        int $code = 303,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: "Telegram requested DC migration to DC_{$dcId}",
            $code,
            $previous
        );
    }

    public function getDcId(): int
    {
        return $this->dcId;
    }
}
