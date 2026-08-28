<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Methods;

use BadMethodCallException;
use MeRezaRezaei\Teleproto\Methods\Generated\Account;
use MeRezaRezaei\Teleproto\Methods\Generated\Auth;
use MeRezaRezaei\Teleproto\Methods\Generated\Bots;
use MeRezaRezaei\Teleproto\Methods\Generated\Contacts;
use MeRezaRezaei\Teleproto\Methods\Generated\Help;
use MeRezaRezaei\Teleproto\Methods\Generated\Messages;
use MeRezaRezaei\Teleproto\Methods\Generated\Users;

/**
 * Entry point for the curated fluent request builders.
 *
 *     Methods::messages()->sendMessage()->peer([...])->message('hi')->randomId(1)->toRequest()
 *     Methods::bots()->sendMessage()->chatId('@channel')->text('hi')->toRequest()
 *
 * The generated groups cover config/curated-methods.json only; extend that
 * list and run `php bin/generate-method-builders.php` to grow the surface.
 */
final class Methods
{
    public static function messages(): Messages
    {
        return new Messages();
    }

    public static function users(): Users
    {
        return new Users();
    }

    public static function contacts(): Contacts
    {
        return new Contacts();
    }

    public static function account(): Account
    {
        return new Account();
    }

    public static function auth(): Auth
    {
        return new Auth();
    }

    public static function help(): Help
    {
        return new Help();
    }

    public static function bots(): Bots
    {
        return new Bots();
    }

    /**
     * Resolve groups that exist but have no explicit accessor yet (added by
     * a later curated-list regeneration); anything unknown fails loudly.
     */
    public static function __callStatic(string $name, array $arguments): object
    {
        $class = 'MeRezaRezaei\\Teleproto\\Methods\\Generated\\' . ucfirst($name);
        if (class_exists($class)) {
            return new $class();
        }

        throw new BadMethodCallException(
            "Methods::{$name}() is not a generated builder group. Add its methods to config/curated-methods.json and run php bin/generate-method-builders.php."
        );
    }
}
