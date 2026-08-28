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
     * Documented error tables: message => [docs HTTP code, verbatim description].
     * Remediation lines (prefixed with an arrow) are ours; descriptions are not.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    protected static function documentedErrors(): array
    {
        return [
            // auth.sendCode + auth.exportLoginToken + auth.importBotAuthorization
            'API_ID_INVALID' => [400, 'API ID invalid. → Check the api_id/api_hash pair from my.telegram.org.'],
            'API_ID_PUBLISHED_FLOOD' => [400, "This API id was published somewhere, you can't use it now. → Obtain your own api_id from my.telegram.org."],
            'ACCESS_TOKEN_EXPIRED' => [400, 'Access token expired. → The bot token was revoked or rolled: get a fresh one from @BotFather.'],
            'ACCESS_TOKEN_INVALID' => [400, 'Access token invalid. → Re-check the bot token from @BotFather.'],
            'AUTH_RESTART' => [500, 'Restart the authorization process. → Start over from auth.sendCode / auth.exportLoginToken.'],

            // auth.sendCode
            'PHONE_NUMBER_APP_SIGNUP_FORBIDDEN' => [400, "You can't sign up using this app. → Sign up with an official client first, or use another app's api credentials."],
            'PHONE_NUMBER_BANNED' => [400, 'The provided phone number is banned from telegram. → Nothing a client can do; the number is banned.'],
            'PHONE_NUMBER_FLOOD' => [400, 'You asked for the code too many times. → Wait before requesting another code.'],
            'PHONE_NUMBER_INVALID' => [406, 'The phone number is invalid. → Use full international format, e.g. +989123456789.'],
            'PHONE_PASSWORD_FLOOD' => [406, 'You have tried logging in too many times. → Wait before retrying the login.'],
            'PHONE_PASSWORD_PROTECTED' => [400, 'This phone is password protected.'],
            'SMS_CODE_CREATE_FAILED' => [400, 'An error occurred while creating the SMS code. → Retry auth.sendCode.'],
            'UPDATE_APP_TO_LOGIN' => [406, 'Please update your client to login. → Bump app_version/lang_code in initConnection and retry.'],

            // auth.signIn
            'PHONE_CODE_EMPTY' => [400, 'phone_code is missing. → Ask the user for the login code and send it in phone_code.'],
            'PHONE_CODE_EXPIRED' => [400, 'The phone code you provided has expired. → Request a new code (auth.resendCode).'],
            'PHONE_CODE_INVALID' => [400, 'The provided phone code is invalid. → Re-check the digits the user entered.'],
            'PHONE_NUMBER_UNOCCUPIED' => [400, 'The phone number is not yet being used. → Complete signup (auth.signUp) or use another number.'],
            'SIGN_IN_FAILED' => [500, 'Failure while signing in. → Retry the sign-in.'],

            // auth.checkPassword
            'PASSWORD_HASH_INVALID' => [400, 'The provided password hash is invalid. → Wrong 2FA password; re-ask the user, do not blind-retry.'],
            'SRP_ID_INVALID' => [400, 'Invalid SRP ID provided. → Re-fetch account.getPassword and rebuild the SRP proof.'],
            'SRP_PASSWORD_CHANGED' => [400, 'Password has changed. → Re-fetch account.getPassword and restart the SRP flow.'],
            'AUTH_KEY_UNSYNCHRONIZED' => [500, 'Internal error, please repeat the method call. → Safe to retry.'],

            // QR login (documented on auth.acceptLoginToken; also surfaced by the login flow)
            'AUTH_TOKEN_INVALID' => [400, 'Invalid token provided.'],
            'AUTH_TOKEN_EXPIRED' => [400, 'Token has expired. → Re-export a fresh QR login token.'],
            'AUTH_TOKEN_ALREADY_ACCEPTED' => [400, 'Token was already used. → Re-export a fresh QR login token.'],

            // 2FA gate (https://core.telegram.org/api/auth#2fa)
            'SESSION_PASSWORD_NEEDED' => [401, '2FA is enabled on this account. → Fetch account.getPassword and complete the SRP flow (auth.checkPassword).'],
        ];
    }

    public static function resolve(string $errorMessage, int $errorCode = 0): TelegramException
    {
        $message = strtoupper(trim($errorMessage));

        // FLOOD_WAIT_X / FLOOD_PREMIUM_WAIT_X — https://core.telegram.org/api/errors#flood-wait-errors
        if (preg_match('/^FLOOD(?:_PREMIUM)?_WAIT_(\d+)$/', $message, $m)) {
            return new FloodWaitException((int)$m[1], $message, $errorCode);
        }

        // PHONE_MIGRATE_X (registered users) / NETWORK_MIGRATE_X (new, by IP) — https://core.telegram.org/api/datacenter
        if (preg_match('/^(?:PHONE|NETWORK|USER)_MIGRATE_(\d+)$/', $message, $m)) {
            return new DcMigrationException(
                (int)$m[1],
                "{$message} — reconnect at DC {$m[1]} and retry (per https://core.telegram.org/api/datacenter)",
                $errorCode
            );
        }

        $doc = self::documentedErrors()[$message] ?? null;

        return match (true) {
            $message === 'SESSION_PASSWORD_NEEDED' => new SessionPasswordNeededException($message, $errorCode),
            $message === 'PASSWORD_HASH_INVALID' => new PasswordHashInvalidException($message, $errorCode),
            str_starts_with($message, 'PHONE_CODE_') => new PhoneCodeException($message, $errorCode),
            str_starts_with($message, 'PHONE_NUMBER_') => new PhoneNumberException($message, $errorCode),
            str_starts_with($message, 'API_ID_') => new ApiIdException($message, $errorCode),
            in_array($message, ['AUTH_KEY_UNREGISTERED', 'AUTH_KEY_INVALID', 'SESSION_REVOKED', 'SESSION_EXPIRED'], true)
                => new AuthKeyException($message, $errorCode),
            default => new RpcErrorException($message, $errorCode, $doc[1] ?? ''),
        };
    }

    /**
     * The docs table entry for a message, if documented.
     *
     * @return array{0: int, 1: string}|null
     */
    public static function documentedEntry(string $errorMessage): ?array
    {
        return self::documentedErrors()[strtoupper(trim($errorMessage))] ?? null;
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
