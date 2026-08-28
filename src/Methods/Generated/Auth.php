<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Methods\Generated;

/**
 * mtproto auth.* curated method builders.
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php from config/curated-methods.json — do not edit by hand.
 */
final class Auth
{
    public function sendCode(): AuthSendCodeBuilder
    {
        return new AuthSendCodeBuilder();
    }

    public function signIn(): AuthSignInBuilder
    {
        return new AuthSignInBuilder();
    }

    public function checkPassword(): AuthCheckPasswordBuilder
    {
        return new AuthCheckPasswordBuilder();
    }

    public function exportLoginToken(): AuthExportLoginTokenBuilder
    {
        return new AuthExportLoginTokenBuilder();
    }

    public function importBotAuthorization(): AuthImportBotAuthorizationBuilder
    {
        return new AuthImportBotAuthorizationBuilder();
    }
}

/**
 * Fluent builder for auth.sendCode (mtproto, return: auth.SentCode).
 * Send the verification code for login
 * Docs: https://core.telegram.org/method/auth.sendCode
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class AuthSendCodeBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function phoneNumber(string $phone_number): self
    {
        $this->p['phone_number'] = $phone_number;

        return $this;
    }

    public function apiId(int $api_id): self
    {
        $this->p['api_id'] = $api_id;

        return $this;
    }

    public function apiHash(string $api_hash): self
    {
        $this->p['api_hash'] = $api_hash;

        return $this;
    }

    public function settings(mixed $settings): self
    {
        $this->p['settings'] = $settings;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['phone_number', 'api_id', 'api_hash', 'settings'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('auth.sendCode: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'auth.sendCode'], $this->p);
    }
}

/**
 * Fluent builder for auth.signIn (mtproto, return: auth.Authorization).
 * Signs in a user with a validated phone number.
 * Docs: https://core.telegram.org/method/auth.signIn
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class AuthSignInBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function phoneNumber(string $phone_number): self
    {
        $this->p['phone_number'] = $phone_number;

        return $this;
    }

    public function phoneCodeHash(string $phone_code_hash): self
    {
        $this->p['phone_code_hash'] = $phone_code_hash;

        return $this;
    }

    public function phoneCode(string $phone_code): self
    {
        $this->p['phone_code'] = $phone_code;

        return $this;
    }

    public function emailVerification(mixed $email_verification): self
    {
        $this->p['email_verification'] = $email_verification;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['phone_number', 'phone_code_hash'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('auth.signIn: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'auth.signIn'], $this->p);
    }
}

/**
 * Fluent builder for auth.checkPassword (mtproto, return: auth.Authorization).
 * Try logging to an account protected by a [2FA password](https://core.telegram.org/api/srp).
 * Docs: https://core.telegram.org/method/auth.checkPassword
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class AuthCheckPasswordBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function password(mixed $password): self
    {
        $this->p['password'] = $password;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['password'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('auth.checkPassword: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'auth.checkPassword'], $this->p);
    }
}

/**
 * Fluent builder for auth.exportLoginToken (mtproto, return: auth.LoginToken).
 * Generate a login token, for [login via QR code](https://core.telegram.org/api/qr-login).   The generated login token should be encoded using base64url, then shown as a `tg://login?token=base64encodedtoken` [deep link »](https://core.telegram.org/api/links#qr-code-login-links) in the QR code.  For more info, see [login via QR code](https://core.telegram.org/api/qr-login).
 * Docs: https://core.telegram.org/method/auth.exportLoginToken
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class AuthExportLoginTokenBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function apiId(int $api_id): self
    {
        $this->p['api_id'] = $api_id;

        return $this;
    }

    public function apiHash(string $api_hash): self
    {
        $this->p['api_hash'] = $api_hash;

        return $this;
    }

    public function exceptIds(array $except_ids): self
    {
        $this->p['except_ids'] = $except_ids;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['api_id', 'api_hash', 'except_ids'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('auth.exportLoginToken: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'auth.exportLoginToken'], $this->p);
    }
}

/**
 * Fluent builder for auth.importBotAuthorization (mtproto, return: auth.Authorization).
 * Login as a bot
 * Docs: https://core.telegram.org/method/auth.importBotAuthorization
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class AuthImportBotAuthorizationBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    public function flags(int $flags): self
    {
        $this->p['flags'] = $flags;

        return $this;
    }

    public function apiId(int $api_id): self
    {
        $this->p['api_id'] = $api_id;

        return $this;
    }

    public function apiHash(string $api_hash): self
    {
        $this->p['api_hash'] = $api_hash;

        return $this;
    }

    public function botAuthToken(string $bot_auth_token): self
    {
        $this->p['bot_auth_token'] = $bot_auth_token;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $missing = [];
        foreach (['flags', 'api_id', 'api_hash', 'bot_auth_token'] as $required) {
            if (! array_key_exists($required, $this->p)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException('auth.importBotAuthorization: missing ' . implode(', ', $missing));
        }

        return array_merge(['_' => 'auth.importBotAuthorization'], $this->p);
    }
}
