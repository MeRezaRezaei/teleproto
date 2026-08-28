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
        $vars = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $vars[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
        }
        return $vars;
    }

    /**
     * Inserts or replaces KEY="value" in the .env at $path.
     */
    public static function upsert(string $path, string $key, string $value): void
    {
        $content = file_exists($path) ? (string) file_get_contents($path) : '';
        if (preg_match("/^{$key}=.*/m", $content)) {
            $content = (string) preg_replace("/^{$key}=.*/m", "{$key}=\"{$value}\"", $content);
        } else {
            $content .= (rtrim($content) !== '' ? "\n" : '') . "{$key}=\"{$value}\"\n";
        }
        file_put_contents($path, $content);
    }
}
