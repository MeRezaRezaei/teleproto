<?php

declare(strict_types=1);

// Generates the curated fluent request builders under src/Methods/Generated/
// from config/curated-methods.json validated against the schema artifacts
// via MethodRegistry. Run: php bin/generate-method-builders.php
//
// Zero regex (house rule): all name work is explode/ucfirst string code.
// Builders are pure request shapers — they never read or assume the schema
// layer. Builder class names are group-qualified: mtproto
// messages.sendMessage -> MessagesSendMessageBuilder, bot-http sendMessage
// -> BotSendMessageBuilder. Flat names cannot work: messages.search and
// contacts.search would both flatten to SearchBuilder, and short bot
// names would shadow mtproto ones. Qualification is stable no matter how
// the curated list grows.

require __DIR__ . '/../vendor/autoload.php';

use MeRezaRezaei\Teleproto\Schema\MethodRegistry;
use MeRezaRezaei\Teleproto\Schema\TelegramMethod;

const GENERATED_NS = 'MeRezaRezaei\\Teleproto\\Methods\\Generated';
const HEADER_DATE = '2026-08-28';

$configPath = __DIR__ . '/../config/curated-methods.json';
$outDir = __DIR__ . '/../src/Methods/Generated';

$errors = [];

$raw = file_get_contents($configPath);
if ($raw === false) {
    fwrite(STDERR, "curated-methods.json is missing at [{$configPath}].\n");
    exit(1);
}

$curated = json_decode($raw, true);
if (! is_array($curated) || ! is_array($curated['mtproto'] ?? null) || ! is_array($curated['bot-http'] ?? null)) {
    fwrite(STDERR, "Malformed curated-methods.json: expected {\"mtproto\": [...], \"bot-http\": [...]}.\n");
    exit(1);
}

MethodRegistry::load();

$stringList = static function (array $list, string $section) use (&$errors): array {
    $names = [];
    $position = 0;
    foreach ($list as $name) {
        $position++;
        if (! is_string($name) || $name === '') {
            $errors[] = "curated-methods.json {$section} entry #{$position} is not a non-empty string.";
            continue;
        }
        $names[] = $name;
    }

    return $names;
};

$isIdentifier = static function (string $token): bool {
    if ($token === '') {
        return false;
    }
    foreach (str_split($token) as $index => $char) {
        if ($char === '_' || ctype_alpha($char)) {
            continue;
        }
        if ($index > 0 && ctype_digit($char)) {
            continue;
        }

        return false;
    }

    return true;
};

$camelOf = static function (string $token): string {
    $out = '';
    foreach (explode('_', $token) as $index => $part) {
        $out .= $index === 0 ? lcfirst($part) : ucfirst($part);
    }

    return $out;
};

$studlyOf = static function (string $token): string {
    $out = '';
    foreach (explode('_', $token) as $part) {
        $out .= ucfirst($part);
    }

    return $out;
};

$nativeTypeOf = static function (string $api, string $type, string $name): string {
    if ($api === 'bot-http' && in_array($name, ['chat_id', 'chat_username', 'sender_chat_id'], true)) {
        return 'int|string';
    }
    if ($type === 'Vector<int>' || $type === 'Vector<long>') {
        return 'array';
    }

    return match ($type) {
        'int', 'long' => 'int',
        'string', 'bytes' => 'string',
        'bool', 'true' => 'bool',
        'float', 'double' => 'float',
        'array' => 'array',
        default => 'mixed',
    };
};

$requiredNamesOf = static function (TelegramMethod $method): array {
    if ($method->api === 'bot-http') {
        return $method->required;
    }

    $required = [];
    foreach ($method->params as $param) {
        if ($param['flag_word'] === null) {
            $required[] = $param['name'];
        }
    }

    return $required;
};

$flatDescription = static function (string $description): string {
    return trim(str_replace(["\r", "\n"], ' ', $description));
};

$renderBuilder = static function (TelegramMethod $method, string $builderClass) use ($camelOf, $nativeTypeOf, $requiredNamesOf, $flatDescription, &$errors): string {
    $lines = [];
    $lines[] = '/**';
    $lines[] = ' * Fluent builder for ' . $method->name . ' (' . $method->api . ', return: ' . ($method->returnType !== '' ? $method->returnType : 'unknown') . ').';
    if ($method->description !== '') {
        $lines[] = ' * ' . $flatDescription($method->description);
    }
    if ($method->docs !== '') {
        $lines[] = ' * Docs: ' . $method->docs;
    }
    $lines[] = ' *';
    $lines[] = ' * @generated ' . HEADER_DATE . ' by bin/generate-method-builders.php — do not edit by hand.';
    $lines[] = ' */';
    $lines[] = 'final class ' . $builderClass;
    $lines[] = '{';
    $lines[] = '    /** @var array<string, mixed> */';
    $lines[] = '    private array $p = [];';
    $lines[] = '';

    foreach ($method->params as $param) {
        $snake = $param['name'];
        $setterName = $camelOf($snake);
        if ($setterName === 'toRequest' || $setterName === 'p') {
            $errors[] = "{$method->name}: param [{$snake}] collides with the builder contract.";
            continue;
        }

        $native = $nativeTypeOf($method->api, $param['type'], $snake);
        $lines[] = '    public function ' . $setterName . '(' . $native . ' $' . $snake . '): self';
        $lines[] = '    {';
        $lines[] = '        $this->p[' . var_export($snake, true) . '] = $' . $snake . ';';
        $lines[] = '';
        $lines[] = '        return $this;';
        $lines[] = '    }';
        $lines[] = '';
    }

    $required = $requiredNamesOf($method);
    foreach ($required as $requiredName) {
        if (! in_array($requiredName, $method->paramNames(), true)) {
            $errors[] = "{$method->name}: required param [{$requiredName}] is not in the artifact params.";
        }
    }

    $lines[] = '    /**';
    $lines[] = '     * @return array<string, mixed>';
    $lines[] = '     */';
    $lines[] = '    public function toRequest(): array';
    $lines[] = '    {';
    if ($required === []) {
        $lines[] = "        return array_merge(['_' => " . var_export($method->name, true) . '], $this->p);';
    } else {
        $requiredSet = '[' . implode(', ', array_map(static fn (string $n): string => var_export($n, true), $required)) . ']';
        $lines[] = '        $missing = [];';
        $lines[] = '        foreach (' . $requiredSet . ' as $required) {';
        $lines[] = '            if (! array_key_exists($required, $this->p)) {';
        $lines[] = '                $missing[] = $required;';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        if ($missing !== []) {';
        $lines[] = '            throw new \InvalidArgumentException(' . var_export($method->name . ': missing ', true) . ' . implode(\', \', $missing));';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = "        return array_merge(['_' => " . var_export($method->name, true) . '], $this->p);';
    }
    $lines[] = '    }';
    $lines[] = '}';

    return implode("\n", $lines);
};

/**
 * @param list<array{method: TelegramMethod, builder: string, factory: string}> $entries
 */
$renderGroup = static function (string $groupClass, string $groupLabel, array $entries) use ($renderBuilder): string {
    $lines = [];
    $lines[] = '/**';
    $lines[] = ' * ' . $groupLabel . '.';
    $lines[] = ' *';
    $lines[] = ' * @generated ' . HEADER_DATE . ' by bin/generate-method-builders.php from config/curated-methods.json — do not edit by hand.';
    $lines[] = ' */';
    $lines[] = 'final class ' . $groupClass;
    $lines[] = '{';
    foreach ($entries as $index => $entry) {
        $lines[] = '    public function ' . $entry['factory'] . '(): ' . $entry['builder'];
        $lines[] = '    {';
        $lines[] = '        return new ' . $entry['builder'] . '();';
        $lines[] = '    }';
        if ($index < count($entries) - 1) {
            $lines[] = '';
        }
    }
    $lines[] = '}';

    $builders = [];
    foreach ($entries as $entry) {
        $builders[] = $renderBuilder($entry['method'], $entry['builder']);
    }

    return "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . GENERATED_NS . ";\n\n"
        . implode("\n", $lines) . "\n\n"
        . implode("\n\n", $builders) . "\n";
};

/**
 * @param array<string, array{class: string, api: string, entries: list<array{method: TelegramMethod, builder: string, factory: string}>}> $groups
 */
$collect = static function (string $api, array $names, array &$groups) use (&$errors, $isIdentifier, $studlyOf, $camelOf): void {
    foreach ($names as $name) {
        if (! MethodRegistry::has($name)) {
            $errors[] = "curated [{$name}] is unknown to the schema artifacts (neither methods-mtproto.json nor methods-botapi.json).";
            continue;
        }

        $method = MethodRegistry::get($name);
        if ($method->api !== $api) {
            $errors[] = "curated [{$name}] is listed under {$api} but the artifacts place it under {$method->api}.";
            continue;
        }

        if ($api === 'bot-http') {
            if (! $isIdentifier($name)) {
                $errors[] = "curated [{$name}] is not a plain identifier.";
                continue;
            }
            $groupKey = 'bot-http';
            $groupClass = 'Bots';
            $factory = $camelOf($name);
            $builder = 'Bot' . ucfirst($factory) . 'Builder';
        } else {
            $dot = strpos($name, '.');
            $segment = $dot === false ? '' : substr($name, 0, $dot);
            $methodPart = $dot === false ? '' : substr($name, $dot + 1);
            $trailingDot = $dot === false ? false : strpos($name, '.', $dot + 1);
            if ($segment === '' || $methodPart === '' || $trailingDot !== false || ! $isIdentifier($segment) || ! $isIdentifier($methodPart)) {
                $errors[] = "curated mtproto [{$name}] must be exactly namespace.method.";
                continue;
            }
            $groupKey = $segment;
            $groupClass = $studlyOf($segment);
            $factory = $camelOf($methodPart);
            $builder = $groupClass . ucfirst($factory) . 'Builder';
        }

        $groups[$groupKey]['class'] = $groupClass;
        $groups[$groupKey]['api'] = $api;
        $groups[$groupKey]['entries'][] = ['method' => $method, 'builder' => $builder, 'factory' => $factory];
    }
};

$groups = [];
$collect('mtproto', $stringList($curated['mtproto'], 'mtproto'), $groups);
$collect('bot-http', $stringList($curated['bot-http'], 'bot-http'), $groups);

$declared = [];
foreach ($groups as $groupKey => $group) {
    foreach ($group['entries'] as $entry) {
        $fqcn = GENERATED_NS . '\\' . $entry['builder'];
        if (isset($declared[$fqcn])) {
            $errors[] = 'builder class [' . $entry['builder'] . "] would be declared twice ({$declared[$fqcn]} and {$entry['method']->name}).";
        }
        $declared[$fqcn] = $entry['method']->name;
    }
    if (isset($declared[GENERATED_NS . '\\' . $group['class']])) {
        $errors[] = "group class [{$group['class']}] collides with a builder class name.";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "generate-method-builders failed — fix config/curated-methods.json:\n  - " . implode("\n  - ", $errors) . "\n");
    exit(1);
}

if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
foreach (scandir($outDir) as $entry) {
    if (str_ends_with($entry, '.php')) {
        unlink($outDir . '/' . $entry);
    }
}

$written = 0;
$total = 0;
foreach ($groups as $groupKey => $group) {
    $label = $group['api'] === 'bot-http'
        ? 'Bot API (bot-http) curated method builders'
        : 'mtproto ' . $groupKey . '.* curated method builders';
    $file = $renderGroup($group['class'], $label, $group['entries']);
    if (file_put_contents($outDir . '/' . $group['class'] . '.php', $file) === false) {
        fwrite(STDERR, "cannot write {$outDir}/{$group['class']}.php\n");
        exit(1);
    }
    $written++;
    $total += count($group['entries']);
    echo $group['class'] . '.php: ' . count($group['entries']) . " builders\n";
}

echo "wrote {$written} group files, {$total} builders to src/Methods/Generated\n";
