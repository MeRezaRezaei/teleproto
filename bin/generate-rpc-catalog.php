<?php

declare(strict_types=1);

// Generates src/Exceptions/Rpc/RpcErrorCatalog.php from the official
// machine-readable RPC error database. Run: php bin/generate-rpc-catalog.php

$j = json_decode((string) file_get_contents('/tmp/opencode/errors.json'), true);
if (!is_array($j) || !isset($j['descriptions'])) {
    fwrite(STDERR, "errors.json missing or malformed\n");
    exit(1);
}

$codeOf = [];
foreach ($j['errors'] as $code => $messages) {
    foreach (array_keys($messages) as $msg) {
        $codeOf[$msg] ??= (int) $code;
    }
}
$desc = $j['descriptions'];

$export = static function (array $a, string $indent) use (&$export): string {
    $lines = [];
    foreach ($a as $k => $v) {
        $k = var_export((string) $k, true);
        $lines[] = is_array($v)
            ? $indent . $k . ' => [' . PHP_EOL . $export($v, $indent . '    ') . $indent . '],'
            : $indent . $k . ' => ' . var_export($v, true) . ',';
    }
    return implode(PHP_EOL, $lines);
};
$list = static fn (array $a): string => implode('', array_map(
    fn ($m) => PHP_EOL . '        ' . var_export($m, true) . ',',
    $a
));

$class = <<<'PHP'
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Exceptions\Rpc;

/**
 * The complete official Telegram RPC error database, generated from
 * https://core.telegram.org/api/errors.json (layer __LAYER__).
 *
 * Descriptions are Telegram's official wording verbatim; messages and
 * descriptions may contain %d placeholders for durations/values.
 * Regenerate after layer bumps by re-fetching the JSON permalink.
 *
 * @generated 2026-08-28 — do not hand-edit the data arrays.
 * @internal
 */
final class RpcErrorCatalog
{
    public const LAYER = __LAYER__;

    public const SOURCE = 'https://core.telegram.org/api/errors.json';

    /** @var array<string, string> message template => official description template */
    private const DESCRIPTIONS = [
__DESC_BLOCK__
    ];

    /** @var array<string, int> message template => documented status code */
    private const CODES = [
__CODE_BLOCK__
    ];

    /** @var list<string> methods only usable by user accounts */
    private const USER_ONLY = [__USER_BLOCK__
    ];

    /** @var list<string> methods only usable by bots */
    private const BOT_ONLY = [__BOT_BLOCK__
    ];

    /** @var list<string> methods usable before login */
    private const UNAUTHED_ALLOWED = [__UNAUTH_BLOCK__
    ];

    /** @var array<string, string>|null */
    private static ?array $templates = null;

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return self::DESCRIPTIONS;
    }

    public static function codeOf(string $messageTemplate): ?int
    {
        return self::CODES[$messageTemplate] ?? null;
    }

    /**
     * Exact or %d-template match against a wire message.
     * Returns [template, renderedDescription] or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function lookup(string $wireMessage): ?array
    {
        $msg = strtoupper(trim($wireMessage));
        if (isset(self::DESCRIPTIONS[$msg])) {
            return [$msg, self::DESCRIPTIONS[$msg]];
        }

        foreach (self::templates() as $template => $descTemplate) {
            $pattern = '/^' . str_replace('%d', '(\d+)', preg_quote($template, '/')) . '$/';
            if (preg_match($pattern, $msg, $m)) {
                array_shift($m);
                return [$template, vsprintf($descTemplate, $m)];
            }
        }
        return null;
    }

    /**
     * @return array<string, string> only the templates containing %d
     */
    private static function templates(): array
    {
        if (self::$templates === null) {
            self::$templates = [];
            foreach (self::DESCRIPTIONS as $template => $descTemplate) {
                if (str_contains($template, '%d')) {
                    self::$templates[$template] = $descTemplate;
                }
            }
        }
        return self::$templates;
    }

    /**
     * @return list<string>
     */
    public static function userOnlyMethods(): array
    {
        return self::USER_ONLY;
    }

    /**
     * @return list<string>
     */
    public static function botOnlyMethods(): array
    {
        return self::BOT_ONLY;
    }

    /**
     * @return list<string>
     */
    public static function unauthedAllowedMethods(): array
    {
        return self::UNAUTHED_ALLOWED;
    }
}

PHP;

$out = str_replace(
    ['__LAYER__', '__DESC_BLOCK__', '__CODE_BLOCK__', '__USER_BLOCK__', '__BOT_BLOCK__', '__UNAUTH_BLOCK__'],
    [(string) $j['layer'], $export($desc, '        '), $export($codeOf, '        '), $list($j['user_only'] ?? []), $list($j['bot_only'] ?? []), $list($j['unauthed_allowed'] ?? [])],
    str_replace('__LAYER__', (string) $j['layer'], $class)
);

file_put_contents(__DIR__ . '/../src/Exceptions/Rpc/RpcErrorCatalog.php', $out);
printf("written: %d KB, %d descriptions, %d codes, layer %d\n", ...[
    (int) (filesize(__DIR__ . '/../src/Exceptions/Rpc/RpcErrorCatalog.php') / 1024),
    count($desc),
    count($codeOf),
    $j['layer'],
]);
