<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Support;

/**
 * Tiny .env reader/writer for CLI flows. Laravel has no official .env
 * writer (it treats .env as deploy-time config), so this stays ours —
 * deliberately: reader parses KEY=VALUE lines, writer upserts one key.
 */
final class EnvFile
{
    /** @return array<string, string> */
    public static function read(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $parsed = \Dotenv\Dotenv::parse((string) file_get_contents($path));
        return array_map(strval(...), $parsed);
    }

    /**
     * Inserts or replaces KEY="value" in the .env at $path.
     */
    public static function upsert(string $path, string $key, string $value): void
    {
        $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        $prefix = $key . '=';
        $found = false;
        foreach ($lines as $i => $line) {
            if (str_starts_with(ltrim($line), $prefix)) {
                $lines[$i] = $key . '="' . $value . '"';
                $found = true; // no break: rewrite EVERY duplicate (regex-era replace semantics)
            }
        }
        if (!$found) {
            $lines[] = $key . '="' . $value . '"';
        }
        file_put_contents($path, implode("\n", $lines) . "\n");
    }
}
