#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$spec = (array) json_decode((string) file_get_contents("{$root}/schema/sources/botapi-spec.json"), true);

$typeMap = static fn (array $types): string => match (true) {
    in_array('Integer', $types, true) => 'int',
    in_array('Float', $types, true) || in_array('Float number', $types, true) => 'float',
    in_array('Boolean', $types, true) => 'bool',
    str_contains(implode('|', $types), 'Array') => 'array',
    default => 'string',
};

$methods = [];
foreach (($spec['methods'] ?? []) as $name => $m) {
    $params = [];
    $required = [];
    foreach (($m['fields'] ?? []) as $f) {
        $params[] = [
            'name' => (string) $f['name'],
            'type' => $typeMap((array) ($f['types'] ?? [])),
            'required' => (bool) ($f['required'] ?? false),
            'description' => (string) ($f['description'] ?? ''),
        ];
        if (!empty($f['required'])) {
            $required[] = (string) $f['name'];
        }
    }
    $methods[$name] = [
        'docs' => (string) ($m['href'] ?? "https://core.telegram.org/bots/api#{$name}"),
        'description' => implode(' ', (array) ($m['description'] ?? [])),
        'params' => $params,
        'required' => $required,
        'returns' => (array) ($m['returns'] ?? []),
        'errors' => [],
    ];
}
ksort($methods);

// Optional argv[1]: output directory (defaults to repo schema/; source always read from repo schema/sources/)
$outDir = isset($argv[1]) && is_string($argv[1]) ? rtrim($argv[1], '/') : "{$root}/schema";
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents(
    "{$outDir}/methods-botapi.json",
    json_encode([
        '_generated' => true,
        'api' => 'bot-http',
        'source' => 'PaulSonOfLars/telegram-bot-api-spec api.json',
        'methods' => $methods,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
printf("methods-botapi.json: %d methods\n", count($methods));
