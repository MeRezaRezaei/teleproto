<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

use MeRezaRezaei\Teleproto\Exceptions\DcMigrationException;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;

/**
 * Maps Telegram rpc_error messages (and transport-level int32 codes) to
 * typed exceptions carrying documentation-backed hints, so callers can
 * branch on the failure and act step by step. Unknown errors still come
 * back as RpcErrorException with the raw message preserved.
 *
 * Error semantics sourced from the official docs:
 * @see https://core.telegram.org/api/errors
 */
final class RpcExceptionResolver
{
    /**
     * Raw transport-level int32 codes the server can send as a whole frame.
     */
    public const TRANSPORT_AUTH_KEY_UNKNOWN = -404;

    public static function resolve(string $errorMessage, int $errorCode = 0): TelegramException
    {
        $message = strtoupper(trim($errorMessage));

        // Parameterized: FLOOD_WAIT_X (also FLOOD_PREMIUM_WAIT_X)
        if (preg_match('/^FLOOD(?:_PREMIUM)?_WAIT_(\d+)$/', $message, $m)) {
            return new FloodWaitException((int)$m[1], $message, $errorCode);
        }

        // Parameterized DC migrations: PHONE_MIGRATE_X / NETWORK_MIGRATE_X / USER_MIGRATE_X
        if (preg_match('/^(?:PHONE|NETWORK|USER)_MIGRATE_(\d+)$/', $message, $m)) {
            return new DcMigrationException(
                (int)$m[1],
                "{$message} — reconnect at DC {$m[1]} and retry",
                $errorCode
            );
        }

        return match (true) {
            $message === 'SESSION_PASSWORD_NEEDED' => new SessionPasswordNeededException($message, $errorCode),
            $message === 'PASSWORD_HASH_INVALID' => new PasswordHashInvalidException($message, $errorCode),
            str_starts_with($message, 'PHONE_CODE_') => new PhoneCodeException($message, $errorCode),
            str_starts_with($message, 'PHONE_NUMBER_') => new PhoneNumberException($message, $errorCode),
            str_starts_with($message, 'API_ID_') => new ApiIdException($message, $errorCode),
            in_array($message, ['AUTH_KEY_UNREGISTERED', 'AUTH_KEY_INVALID', 'SESSION_REVOKED', 'SESSION_EXPIRED'], true)
                => new AuthKeyException($message, $errorCode),
            default => new RpcErrorException($message, $errorCode, self::catalogHint($message)),
        };
    }

    /**
     * A whole encrypted frame that decodes to a bare negative int32 is a
     * transport-level error (e.g. -404 = auth key unknown to the server).
     */
    public static function fromTransportCode(int $code): RpcErrorException
    {
        if ($code === self::TRANSPORT_AUTH_KEY_UNKNOWN) {
            return new AuthKeyException('AUTH_KEY_UNREGISTERED', 401);
        }
        return new RpcErrorException('TRANSPORT_' . $code, $code, "Transport-level error code {$code} received from the DC.");
    }

    /**
     * Curated hints for common errors without a dedicated typed class.
     *
     * @return array<string, string>
     */
    protected static function catalog(): array
    {
        return [
            'PHONE_NUMBER_OCCUPIED' => 'Account already exists with this number: sign in instead of signing up.',
            'FIRSTNAME_INVALID' => 'Signup first name rejected (empty or invalid characters).',
            'USERNAME_INVALID' => 'Username not allowed: 5-32 chars, a-z 0-9 underscores.',
            'USERNAME_OCCUPIED' => 'Username already taken.',
            'USERNAME_NOT_MODIFIED' => 'The new username equals the current one.',
            'PEER_ID_INVALID' => 'The peer is unknown in your session cache: resolve it (contacts/updates) before using it.',
            'CHAT_WRITE_FORBIDDEN' => 'You cannot write in this chat.',
            'CHANNEL_PRIVATE' => 'The channel/supergroup is private or you were kicked.',
            'USER_IS_BOT' => 'Bots cannot perform this action.',
            'TIMEOUT' => 'Server-side timeout: safe to retry the request once.',
            'START_PARAM_EMPTY' => 'WebApp start parameter missing.',
        ];
    }

    protected static function catalogHint(string $message): string
    {
        return self::catalog()[$message] ?? '';
    }
}
