<?php

declare(strict_types=1);

// Generates skills/telegram-methods/<method>.md — one deterministic AI-skill
// reference page per curated method (config/curated-methods.json; falls back
// to the pinned seed list from the 2026-08-28 method-layer plan when the
// curated file is not present yet — it is owned by the parallel builders task).
//
// Run: php bin/generate-skill-files.php

require dirname(__DIR__) . '/vendor/autoload.php';

use MeRezaRezaei\Teleproto\Exceptions\Rpc\RpcErrorCatalog;
use MeRezaRezaei\Teleproto\Schema\MethodRegistry;
use MeRezaRezaei\Teleproto\Schema\TelegramMethod;

/** Pinned seed from the plan (kept in sync with config/curated-methods.json). */
const PINNED_SEED = [
    'mtproto' => [
        'messages.sendMessage', 'messages.getHistory', 'messages.search', 'messages.readHistory',
        'messages.sendReaction', 'messages.getDialogs', 'messages.forwardMessages', 'messages.deleteMessages',
        'users.getUsers', 'users.getFullUser', 'contacts.getContacts', 'contacts.importContacts',
        'contacts.search', 'account.getPassword', 'auth.sendCode', 'auth.signIn', 'auth.checkPassword',
        'auth.exportLoginToken', 'auth.importBotAuthorization', 'help.getNearestDc',
    ],
    'bot-http' => [
        'getMe', 'sendMessage', 'sendPhoto', 'sendDocument', 'sendMediaGroup',
        'editMessageText', 'deleteMessage', 'answerCallbackQuery', 'setWebhook', 'getUpdates',
    ],
];

$root = dirname(__DIR__);
$outDir = $root . '/skills/telegram-methods';

$curatedPath = $root . '/config/curated-methods.json';
if (is_file($curatedPath)) {
    $curated = json_decode((string) file_get_contents($curatedPath), true);
    if (! is_array($curated) || ! is_array($curated['mtproto'] ?? null) || ! is_array($curated['bot-http'] ?? null)) {
        fwrite(STDERR, "Malformed curated list [{$curatedPath}].\n");
        exit(1);
    }
} else {
    $curated = PINNED_SEED;
    fwrite(STDERR, "Note: config/curated-methods.json not found; using the pinned seed list.\n");
}

$cell = static function (string $text): string {
    return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], trim($text));
};

$camel = static function (string $snake): string {
    $out = '';
    foreach (explode('_', $snake) as $i => $part) {
        $out .= $i === 0 ? $part : ucfirst($part);
    }
    return $out;
};

$groupOf = static function (TelegramMethod $m): string {
    if ($m->api === 'bot-http') {
        return 'bots';
    }
    $dot = strpos($m->name, '.');

    return $dot === false ? strtolower($m->name) : strtolower(substr($m->name, 0, $dot));
};

$accessorOf = static function (TelegramMethod $m): string {
    $dot = strrpos($m->name, '.');

    return $dot === false ? $m->name : substr($m->name, $dot + 1);
};

$placeholderFor = static function (string $type): string {
    $t = strtolower($type);
    if ($t === 'int' || $t === 'long') {
        return '123';
    }
    if ($t === 'string') {
        return "'text'";
    }

    return "['_' => '…']";
};

$renderUsage = static function (TelegramMethod $m) use ($groupOf, $accessorOf, $camel, $placeholderFor): array {
    $required = [];
    foreach ($m->params as $param) {
        // mtproto: params carried in flag words are optional; bot-http: explicit flag.
        $isRequired = $m->api === 'mtproto'
            ? ($param['flag_word'] ?? null) === null
            : ($param['required'] ?? null) === true;
        if ($isRequired) {
            $required[] = $param;
        }
    }

    $lines = ['$request = Methods::' . $groupOf($m) . '()->' . $accessorOf($m) . '()'];
    foreach (array_slice($required, 0, 3) as $param) {
        $lines[] = '    ->' . $camel($param['name']) . '(' . $placeholderFor($param['type']) . ')';
    }
    $lines[] = '    ->toRequest();';
    $lines[] = '';
    $lines[] = '$result = TeleprotoClient::dispatch($request);';

    return $lines;
};

$render = static function (TelegramMethod $m) use ($cell, $renderUsage): string {
    $lines = ['<!-- @generated -->'];
    $lines[] = '';
    $lines[] = '# ' . $m->name;
    if ($m->docs !== '') {
        $lines[] = '';
        $lines[] = '[Docs](' . $m->docs . ')';
    }
    if ($m->description !== '') {
        $lines[] = '';
        $lines[] = $m->description;
    }

    $lines[] = '';
    $lines[] = '## Parameters';
    $lines[] = '';
    $lines[] = '| name | type | required | description |';
    $lines[] = '| --- | --- | --- | --- |';
    foreach ($m->params as $param) {
        $isRequired = $m->api === 'mtproto'
            ? ($param['flag_word'] ?? null) === null
            : ($param['required'] ?? null) === true;
        $lines[] = '| ' . implode(' | ', [
            $cell($param['name']),
            $cell($param['type']),
            $isRequired ? '*' : '',
            $cell($param['description']),
        ]) . ' |';
    }

    if ($m->returnType !== '') {
        $lines[] = '';
        $lines[] = '## Returns';
        $lines[] = '';
        $lines[] = $m->returnType;
    }

    if ($m->errors !== []) {
        $errors = $m->errors;
        sort($errors);
        $lines[] = '';
        $lines[] = '## Errors';
        $lines[] = '';
        foreach ($errors as $error) {
            $entry = RpcErrorCatalog::lookup($error);
            if ($entry === null && str_contains($error, '%d')) {
                // Template errors (e.g. SLOWMODE_WAIT_%d) only match the catalog
                // as wire messages; probe with a representative sample value.
                // Some official templates carry more %d specifiers than the
                // message (multi-value descriptions) — catalog lookup() then
                // throws ArgumentCountError; treat those as undocumented.
                try {
                    $entry = RpcErrorCatalog::lookup(str_replace('%d', '30', $error));
                } catch (ArgumentCountError) {
                    $entry = null;
                }
            }
            $lines[] = $entry === null
                ? '- `' . $error . '`'
                : '- `' . $error . '` — ' . $entry[1];
        }
    }

    $lines[] = '';
    $lines[] = '## Usage';
    $lines[] = '';
    $lines[] = '```php';
    foreach ($renderUsage($m) as $line) {
        $lines[] = $line;
    }
    $lines[] = '```';

    return implode("\n", $lines) . "\n";
};

if (! is_dir($outDir) && ! mkdir($outDir, 0777, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Cannot create output directory [{$outDir}].\n");
    exit(1);
}

$count = 0;
foreach (['mtproto', 'bot-http'] as $api) {
    foreach ($curated[$api] as $name) {
        $name = (string) $name;
        $method = MethodRegistry::get($name);
        if ($method->api !== $api) {
            fwrite(STDERR, "Method [{$name}] expected in [{$api}] but lives in [{$method->api}].\n");
            exit(1);
        }
        file_put_contents($outDir . '/' . $name . '.md', $render($method));
        $count++;
    }
}

printf("written: %d skill files to %s\n", $count, $outDir);
