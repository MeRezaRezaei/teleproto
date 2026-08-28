<?php

declare(strict_types=1);

// Generates schema/methods-mtproto.json from the committed tdesktop .tl
// sources, core.telegram.org errors.json and MadelineProto extracted.json.
// Run: php bin/generate-method-schema.php

require __DIR__ . '/../vendor/autoload.php';

use MeRezaRezaei\Teleproto\MTProto\TL\TLSignatureParser;

$root = dirname(__DIR__);
$layer = 0;
/** @var array<string, array{id: string, params: list<array{name: string, type: string, flag_word: string|null, bit: int|null, description: string}>, return: string, docs: string, errors: list<string>, description: string}> $methods */
$methods = [];
$skipped = 0;

// 1) functions from .tl (after ---functions---), layer from LAYER line
foreach (['api.tl', 'mtproto.tl'] as $file) {
    $inFunctions = false;
    $lines = file("{$root}/schema/sources/{$file}", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        fwrite(STDERR, "cannot read {$root}/schema/sources/{$file}\n");
        exit(1);
    }
    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '---functions---') {
            $inFunctions = true;
            continue;
        }
        if ($line === '---types---') {
            $inFunctions = false;
            continue;
        }
        if (str_starts_with($line, '// LAYER ')) {
            $layer = max($layer, (int) substr($line, 9));
            continue;
        }
        if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '---')) {
            continue;
        }
        if (!$inFunctions) {
            continue;
        }
        $line = rtrim($line, ';');
        try {
            $sig = TLSignatureParser::parse($line);
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, "skipping unparseable function line in {$file} ({$e->getMessage()}): {$line}\n");
            $skipped++;
            continue;
        }
        if (!$sig->hasExplicitId) {
            fwrite(STDERR, "skipping function without explicit id in {$file}: {$line}\n");
            $skipped++;
            continue;
        }
        $params = [];
        foreach ($sig->fields as $f) {
            if ($f['type'] === '#') {
                continue; // flag-mask word, not a wire param
            }
            $params[] = [
                'name' => $f['name'],
                'type' => $f['type'],
                'flag_word' => $f['flagWord'],
                'bit' => $f['bit'],
                'description' => '',
            ];
        }
        $methods[$sig->name] = [
            'id' => sprintf('0x%08x', $sig->id),
            'params' => $params,
            'return' => $sig->returnType,
            'docs' => "https://core.telegram.org/method/{$sig->name}",
            'errors' => [],
            'description' => '',
        ];
    }
}

// 2) invert errors.json {code: {MSG: [methods]}} -> method -> [MSG]
$errorsRaw = (array) json_decode((string) file_get_contents("{$root}/schema/sources/errors.json"), true);
$layer = max($layer, (int) ($errorsRaw['layer'] ?? 0));
foreach (($errorsRaw['errors'] ?? []) as $messages) {
    foreach ((array) $messages as $msg => $methodsList) {
        foreach ((array) $methodsList as $m) {
            if (is_string($m) && isset($methods[$m])) {
                $methods[$m]['errors'][] = (string) $msg;
            }
        }
    }
}

// 3) enrich with official doc descriptions (MadelineProto extracted.json)
$docsMap = [];
$docsPath = "{$root}/schema/sources/extracted.json";
if (file_exists($docsPath)) {
    $docsMap = (array) json_decode((string) file_get_contents($docsPath), true);
}
$escapeKey = static fn (string $s): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
$unescape = static fn (string $s): string => str_replace(['&lt;', '&gt;', '&amp;'], ['<', '>', '&'], $s);
foreach ($methods as $name => &$m) {
    $m['description'] = (string) ($docsMap["method_{$name}"] ?? '');
    foreach ($m['params'] as $i => $p) {
        // conditional params are keyed WITH the flag prefix in extracted.json
        // (HTML-escaped): method_X_param_f_type_flags.N?Vector&lt;T&gt;
        $typePart = $p['flag_word'] !== null
            ? "{$p['flag_word']}.{$p['bit']}?{$p['type']}"
            : $p['type'];
        $key = "method_{$name}_param_{$p['name']}_type_{$escapeKey($typePart)}";
        $m['params'][$i]['description'] = isset($docsMap[$key]) ? $unescape((string) $docsMap[$key]) : '';
    }
    $m['errors'] = array_values(array_unique($m['errors']));
    sort($m['errors']);
}
unset($m);

ksort($methods);
file_put_contents(
    "{$root}/schema/methods-mtproto.json",
    json_encode([
        '_generated' => true,
        'api' => 'mtproto',
        'layer' => $layer,
        'source' => 'tdesktop dev api.tl + mtproto.tl + core.telegram.org/api/errors.json + MadelineProto extracted.json',
        'methods' => $methods,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
printf("methods-mtproto.json: %d methods, layer %d (%d skipped)\n", count($methods), $layer, $skipped);
