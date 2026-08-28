<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

use MeRezaRezaei\Teleproto\Exceptions\DcMigrationException;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;

/**
 * Maps Telegram rpc_error messages (and transport-level int32 codes) to
 * typed exceptions whose hints are taken VERBATIM from the official
 * per-method "Possible errors" tables at core.telegram.org, scoped to the
 * authentication methods Teleproto implements:
 *
 *   auth.sendCode               https://core.telegram.org/method/auth.sendCode
 *   auth.signIn                 https://core.telegram.org/method/auth.signIn
 *   auth.checkPassword          https://core.telegram.org/method/auth.checkPassword
 *   auth.exportLoginToken       https://core.telegram.org/method/auth.exportLoginToken
 *   auth.importLoginToken       https://core.telegram.org/method/auth.importLoginToken
 *   auth.importBotAuthorization https://core.telegram.org/method/auth.importBotAuthorization
 *
 * General behaviors (flood waits, DC migration, 2FA) are documented at:
 *   https://core.telegram.org/api/errors  •  /api/datacenter  •  /api/auth
 */
final class RpcExceptionResolver
{
    /**
     * Raw transport-level int32 codes the server can send as a whole frame.
     */
    public const TRANSPORT_AUTH_KEY_UNKNOWN = -404;

    /**
     * Curated remediations layered ON TOP of the official catalog wording
     * for auth-flow errors callers act on. Official descriptions come from
     * RpcErrorCatalog; only the arrow text after " → " is ours.
     *
     * @return array<string, string> message => remediation (without official description)
     */
    protected static function remediations(): array
    {
        return [
            'API_ID_INVALID' => 'Check the api_id/api_hash pair from my.telegram.org.',
            'API_ID_PUBLISHED_FLOOD' => 'Obtain your own api_id from my.telegram.org.',
            'ACCESS_TOKEN_EXPIRED' => 'The bot token was revoked or rolled: get a fresh one from @BotFather.',
            'ACCESS_TOKEN_INVALID' => 'Re-check the bot token from @BotFather.',
            'AUTH_RESTART' => 'Start over from auth.sendCode / auth.exportLoginToken.',
            'PHONE_NUMBER_APP_SIGNUP_FORBIDDEN' => "Sign up with an official client first, or use another app's api credentials.",
            'PHONE_NUMBER_FLOOD' => 'Wait before requesting another code.',
            'PHONE_NUMBER_INVALID' => 'Use full international format, e.g. +989123456789.',
            'PHONE_PASSWORD_FLOOD' => 'Wait before retrying the login.',
            'SMS_CODE_CREATE_FAILED' => 'Retry auth.sendCode.',
            'UPDATE_APP_TO_LOGIN' => 'Bump app_version/lang_code in initConnection and retry.',
            'PHONE_CODE_EMPTY' => 'Ask the user for the login code and send it in phone_code.',
            'PHONE_CODE_EXPIRED' => 'Request a new code (auth.resendCode).',
            'PHONE_CODE_INVALID' => 'Re-check the digits the user entered.',
            'PHONE_NUMBER_UNOCCUPIED' => 'Complete signup (auth.signUp) or use another number.',
            'SIGN_IN_FAILED' => 'Retry the sign-in.',
            'SRP_ID_INVALID' => 'Re-fetch account.getPassword and rebuild the SRP proof.',
            'SRP_PASSWORD_CHANGED' => 'Re-fetch account.getPassword and restart the SRP flow.',
            'AUTH_KEY_UNSYNCHRONIZED' => 'Safe to retry.',
            'AUTH_TOKEN_EXPIRED' => 'Re-export a fresh QR login token.',
            'AUTH_TOKEN_ALREADY_ACCEPTED' => 'Re-export a fresh QR login token.',
            'SESSION_PASSWORD_NEEDED' => 'Fetch account.getPassword and complete the SRP flow (auth.checkPassword).',
        ];
    }

    public static function resolve(string $errorMessage, int $errorCode = 0, ?string $method = null): TelegramException
    {
        $message = strtoupper(trim($errorMessage));

        // FLOOD_WAIT_X / FLOOD_PREMIUM_WAIT_X — sscanf + digit-strict tail, no regex
        // (tailIsDigits rejects '-5'/' 5' sign/whitespace variants %d would admit;
        // trailing %c rejects any suffix; count 1 = exactly one int consumed)
        $seconds = 0;
        $c = "\0";
        foreach (['FLOOD_WAIT_', 'FLOOD_PREMIUM_WAIT_'] as $pfx) {
            if (self::tailIsDigits($message, $pfx)
                && sscanf($message, $pfx . '%d%c', $seconds, $c) === 1) {
                return new FloodWaitException($seconds, $message, $errorCode);
            }
        }

        // FILE/PHONE/NETWORK/USER_MIGRATE_X — https://core.telegram.org/api/errors (303 SEE_OTHER)
        $dc = 0;
        $c = "\0";
        foreach (['FILE_MIGRATE_', 'PHONE_MIGRATE_', 'NETWORK_MIGRATE_', 'USER_MIGRATE_'] as $pfx) {
            if (self::tailIsDigits($message, $pfx)
                && sscanf($message, $pfx . '%d%c', $dc, $c) === 1
                && $dc > 0 && $dc <= 5) {
                return new DcMigrationException(
                    $dc,
                    "{$message} — repeat the request at DC {$dc} (per https://core.telegram.org/api/datacenter)",
                    $errorCode,
                    null
                );
            }
        }

        // Full official database first (all 780 documented errors, layer 227)
        $catalog = RpcErrorCatalog::lookup($message);
        $docsCode = $catalog !== null ? (RpcErrorCatalog::codeOf($catalog[0]) ?? 0) : 0;
        $effectiveCode = $errorCode !== 0 ? $errorCode : $docsCode;

        // Typed classes for errors callers branch on programmatically
        $typed = match (true) {
            $message === 'SESSION_PASSWORD_NEEDED' => new SessionPasswordNeededException($message, $effectiveCode, $method),
            $message === 'PASSWORD_HASH_INVALID' => new PasswordHashInvalidException($message, $effectiveCode, $method),
            str_starts_with($message, 'PHONE_CODE_') => new PhoneCodeException($message, $effectiveCode, $method),
            str_starts_with($message, 'PHONE_NUMBER_') => new PhoneNumberException($message, $effectiveCode, $method),
            str_starts_with($message, 'API_ID_') => new ApiIdException($message, $effectiveCode, $method),
            in_array($message, ['AUTH_KEY_UNREGISTERED', 'AUTH_KEY_INVALID', 'SESSION_REVOKED', 'SESSION_EXPIRED'], true)
                => new AuthKeyException($message, $effectiveCode, $method),
            default => null,
        };

        // Prefer the official catalog description; curated remediation refines auth-flow errors
        $hint = $catalog[1] ?? '';
        $remediation = self::remediations()[$message] ?? null;
        if ($remediation !== null) {
            $hint = ($hint !== '' ? $hint . ' → ' : '') . $remediation;
        }

        // 406 special display guidance — https://core.telegram.org/api/errors#406-not-acceptable
        if ($effectiveCode === 406 && $hint !== '') {
            $hint .= ' · Per docs: do not display 406 errors directly; an updateServiceNotification popup follows with the localized message.';
        }

        if ($typed !== null) {
            return $typed;
        }

        return new RpcErrorException($message, $effectiveCode, $hint, $method);
    }

    /**
     * True when $message is exactly $prefix followed by one or more ASCII
     * digits — sscanf %d alone would also admit sign/whitespace variants
     * ('..._-5', '..._ 5') that the pre-regex anchored matching rejected.
     */
    private static function tailIsDigits(string $message, string $prefix): bool
    {
        if (!str_starts_with($message, $prefix)) {
            return false;
        }
        $tail = substr($message, strlen($prefix));
        return $tail !== '' && strspn($tail, '0123456789') === strlen($tail);
    }

    /**
     * The official database entry for a wire message (template match,
     * %d values rendered into the description), if documented.
     *
     * @return array{0: int, 1: string}|null [code, rendered description]
     */
    public static function documentedEntry(string $errorMessage): ?array
    {
        $hit = RpcErrorCatalog::lookup($errorMessage);
        if ($hit === null) {
            return null;
        }
        return [RpcErrorCatalog::codeOf($hit[0]) ?? 0, $hit[1]];
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
}
