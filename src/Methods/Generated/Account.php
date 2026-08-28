<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Methods\Generated;

/**
 * mtproto account.* curated method builders.
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php from config/curated-methods.json — do not edit by hand.
 */
final class Account
{
    public function getPassword(): AccountGetPasswordBuilder
    {
        return new AccountGetPasswordBuilder();
    }
}

/**
 * Fluent builder for account.getPassword (mtproto, return: account.Password).
 * Obtain configuration for two-factor authorization with password
 * Docs: https://core.telegram.org/method/account.getPassword
 *
 * @generated 2026-08-28 by bin/generate-method-builders.php — do not edit by hand.
 */
final class AccountGetPasswordBuilder
{
    /** @var array<string, mixed> */
    private array $p = [];

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        return array_merge(['_' => 'account.getPassword'], $this->p);
    }
}
