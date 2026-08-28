<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Schema;

use InvalidArgumentException;
use RuntimeException;

/**
 * Static registry over the packaged schema artifacts: every method from both
 * schema/methods-mtproto.json and schema/methods-botapi.json, keyed by name.
 */
final class MethodRegistry
{
    /** @var array<string, TelegramMethod>|null */
    private static ?array $methods = null;

    /**
     * Load both artifacts (package root schema/). Idempotent: later calls are
     * no-ops once the registry is populated.
     *
     * @throws RuntimeException when an artifact is missing or malformed
     */
    public static function load(): void
    {
        if (self::$methods !== null) {
            return;
        }

        $methods = [];

        foreach (['mtproto' => 'methods-mtproto.json', 'bot-http' => 'methods-botapi.json'] as $api => $file) {
            $path = dirname(__DIR__, 2) . '/schema/' . $file;

            $json = file_get_contents($path);
            if ($json === false) {
                throw new RuntimeException("Cannot read schema artifact [{$path}].");
            }

            $envelope = json_decode($json, true);
            if (! is_array($envelope) || ! is_array($envelope['methods'] ?? null)) {
                throw new RuntimeException("Malformed schema artifact [{$path}]: missing methods map.");
            }

            foreach ($envelope['methods'] as $name => $raw) {
                $entry = (array) $raw;
                $entry['api'] = $api;
                $methods[(string) $name] = TelegramMethod::fromArtifact((string) $name, $entry);
            }
        }

        self::$methods = $methods;
    }

    /**
     * @throws InvalidArgumentException when the name exists in neither artifact
     */
    public static function get(string $name): TelegramMethod
    {
        self::load();

        $method = self::$methods[$name] ?? null;
        if ($method === null) {
            throw new InvalidArgumentException("Unknown method [{$name}].");
        }

        return $method;
    }

    public static function has(string $name): bool
    {
        self::load();

        return isset(self::$methods[$name]);
    }

    /**
     * @return 'mtproto'|'bot-http'
     *
     * @throws InvalidArgumentException when the name exists in neither artifact
     */
    public static function apiOf(string $name): string
    {
        return self::get($name)->api;
    }
}
