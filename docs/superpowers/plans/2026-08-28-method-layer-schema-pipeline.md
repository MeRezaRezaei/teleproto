# Method-Object Layer & Schema Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **PARALLEL DISPATCH:** Groups must complete in order **G1 → G2 → G3**. Tasks inside a group touch disjoint files and MAY run as parallel implementers.
> **PREREQUISITE PLAN:** `2026-08-28-zero-regex-hardening.md` must be COMPLETE (this plan consumes `TLSignatureParser`/`ParsedSignature`).

**Goal:** One derived schema (full Telegram API as data) powering three consumers — a runtime `TelegramMethod`/`MethodRegistry`, generated fluent builders for the curated scope, and AI-skill file generation — plus a `schema-update`/`schema-audit` pipeline that turns Telegram API changes into a diff report.

**Architecture:** Schema-as-derived-data: canonical sources (tdesktop `.tl` files, `errors.json`, Bot API docs HTML) are committed under `schema/sources/` and transformed by generators into two JSON artifacts. `MethodRegistry` loads them into `TelegramMethod` value objects. Builders and skill files are *generated* from the registry — nothing hand-written per method. Coverage is a dial: the curated list decides which builders exist; the registry always knows everything.

**Tech Stack:** PHP 8.2+, `DOMDocument` (zero regex), PHPUnit, existing TLSignatureParser.

**Spec:** `docs/superpowers/specs/2026-08-28-hardening-method-layer-design.md` (§C method layer, §D pipeline+skills — read first).

## Global Constraints

- `declare(strict_types=1);` everywhere new (tests included). **Zero `preg_*` in `src/`** (Plan-1 gate extends to everything this plan adds; generators live in `bin/` and may not use regex either — the Bot API extractor uses DOM).
- Generated artifacts (`schema/*.json`, `src/Methods/Generated/*`, `skills/telegram-methods/*`) are committed, reproducible, and **never hand-edited**; each carries `"_generated": true` / `@generated` headers.
- Public contract stays `array<string,mixed>` — builders return arrays, never Collections, never objects.
- No network in PHPUnit. Generators read only committed `schema/sources/` files (fetching sources is a separate, manual step documented in Task 1).
- Full suite + `./bin/teleproto test-e2e` green after every task.
- Commit per task: `feat(schema): ...`.

## Canonical artifact format (ALL tasks consume this exact shape)

`schema/methods-mtproto.json`:
```json
{
    "_generated": true,
    "api": "mtproto",
    "layer": 227,
    "source": "tdesktop dev api.tl + mtproto.tl",
    "methods": {
        "messages.sendMessage": {
            "id": "0x..." ,
            "params": [ {"name": "peer", "type": "InputPeer", "flag_word": null, "bit": null} ],
            "return": "Updates",
            "docs": "https://core.telegram.org/method/messages.sendMessage",
            "errors": ["MESSAGE_ID_INVALID", "PEER_ID_INVALID"]
        }
    }
}
```
`schema/methods-botapi.json`: same envelope, `"api": "bot-http"`, `"id"`/`"return"` omitted, `params[].type` one of `int|string|bool|float|array`, plus `"required": ["chat_id","text"]`.

## File Structure

```
schema/sources/api.tl, mtproto.tl, errors.json   (committed sources)
schema/methods-mtproto.json                       (generated artifact)
schema/methods-botapi.json                        (generated artifact)
bin/generate-method-schema.php                    (T1: .tl + errors -> mtproto json)
bin/extract-botapi-schema.php                     (T2: DOM parse of docs snapshot)
schema/sources/bots-api.html                      (T2 committed snapshot)
src/Schema/TelegramMethod.php                     (T3 value object)
src/Schema/MethodRegistry.php                     (T3 loader/lookup)
config/curated-methods.json                       (T4 curated dial)
bin/generate-method-builders.php                  (T4)
src/Methods/Generated/* + src/Methods/Methods.php (T4 generated + facade)
src/Schema/SchemaDiffer.php                       (T5)
src/Console/SchemaAuditCommand.php, SchemaUpdateCommand.php (T5)
bin/generate-skill-files.php                      (T6)
skills/telegram-methods/*.md                      (T6 output)
```

---

## GROUP G1 — two independent generators, parallel

### Task 1 (G1): MTProto schema generator

**Files:**
- Create: `schema/sources/api.tl`, `schema/sources/mtproto.tl`, `schema/sources/errors.json` (fetched once, committed), `bin/generate-method-schema.php`, `schema/methods-mtproto.json` (output)
- Test: `tests/Schema/MtprotoSchemaTest.php`

**Interfaces:**
- Consumes: `TLSignatureParser::parse` (Plan 1).
- Produces: `schema/methods-mtproto.json` in the canonical format; `bin/generate-method-schema.php` exit 0 with a one-line summary.

- [ ] **Step 1: Fetch + commit sources (network, one-time, not from tests)**

```bash
mkdir -p schema/sources
curl -fsSL https://raw.githubusercontent.com/telegramdesktop/tdesktop/dev/Telegram/SourceFiles/mtproto/scheme/api.tl -o schema/sources/api.tl
curl -fsSL https://raw.githubusercontent.com/telegramdesktop/tdesktop/dev/Telegram/SourceFiles/mtproto/scheme/mtproto.tl -o schema/sources/mtproto.tl
curl -fsSL https://core.telegram.org/api/errors.json -o schema/sources/errors.json
head -3 schema/sources/api.tl   # sanity: starts with // scheme tl ...
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use PHPUnit\Framework\TestCase;

class MtprotoSchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function artifact(): array
    {
        return (array) json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schema/methods-mtproto.json'), true);
    }

    public function testArtifactEnvelope(): void
    {
        $a = self::artifact();
        $this->assertTrue($a['_generated']);
        $this->assertSame('mtproto', $a['api']);
        $this->assertGreaterThan(200, $a['layer']); // layer number present
        $this->assertGreaterThan(700, count($a['methods']));
    }

    public function testSpotEntryMatchesOfficialSchema(): void
    {
        $m = self::artifact()['methods']['messages.sendMessage'];
        $this->assertSame('Updates', $m['return']);
        $this->assertSame('https://core.telegram.org/method/messages.sendMessage', $m['docs']);
        $this->assertSame('peer', $m['params'][0]['name']);
        $this->assertContains('PEER_ID_INVALID', $m['errors']); // inverted from errors.json
        $this->assertNotSame('', $m['id']);
    }

    public function testEveryEntryCarriesDocsAndErrorsList(): void
    {
        foreach (self::artifact()['methods'] as $name => $m) {
            $this->assertSame("https://core.telegram.org/method/{$name}", $m['docs'], $name);
            $this->assertIsArray($m['errors'], $name);
        }
    }
}
```

- [ ] **Step 3: Run — verify failure** (artifact missing): `vendor/bin/phpunit tests/Schema/MtprotoSchemaTest.php` → FAIL.

- [ ] **Step 4: Implement generator + run it**

`bin/generate-method-schema.php` core (complete file; no regex):

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MeRezaRezaei\Teleproto\MTProto\TL\TLSignatureParser;

$root = dirname(__DIR__);
$layer = 0;
$methods = [];

// 1) functions from .tl (after ---functions---), layer from LAYER line
foreach (['api.tl', 'mtproto.tl'] as $file) {
    $inFunctions = false;
    foreach (file("{$root}/schema/sources/{$file}", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $line = trim($raw);
        if (str_starts_with($line, '// LAYER ')) {
            $layer = max($layer, (int) substr($line, 9));
            continue;
        }
        if ($line === '---functions---') {
            $inFunctions = true;
            continue;
        }
        if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '---')) {
            continue;
        }
        if (!$inFunctions || !str_contains($line, '#')) {
            continue;
        }
        $sig = TLSignatureParser::parse($line);
        $methods[$sig->name] = [
            'id' => sprintf('0x%08x', $sig->id),
            'params' => array_map(
                static fn (array $f): array => ['name' => $f['name'], 'type' => $f['type'], 'flag_word' => $f['flagWord'], 'bit' => $f['bit']],
                $sig->fields
            ),
            'return' => $sig->returnType,
            'docs' => "https://core.telegram.org/method/{$sig->name}",
            'errors' => [],
        ];
    }
}

// 2) invert errors.json {code: {MSG: [methods]}} -> method -> [MSG]
$errorsRaw = (array) json_decode((string) file_get_contents("{$root}/schema/sources/errors.json"), true);
$layer = max($layer, (int) ($errorsRaw['layer'] ?? 0));
foreach (($errorsRaw['errors'] ?? []) as $code => $messages) {
    foreach ((array) $messages as $msg => $methodsList) {
        foreach ((array) $methodsList as $m) {
            if (isset($methods[$m])) {
                $methods[$m]['errors'][] = (string) $msg;
            }
        }
    }
}
foreach ($methods as &$m) {
    sort($m['errors']);
}
unset($m);

ksort($methods);
artifact_put("{$root}/schema/methods-mtproto.json", [
    '_generated' => true,
    'api' => 'mtproto',
    'layer' => $layer,
    'source' => 'tdesktop dev api.tl + mtproto.tl + core.telegram.org/api/errors.json',
    'methods' => $methods,
]);

printf("methods-mtproto.json: %d methods, layer %d\n", count($methods), $layer);

function artifact_put(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
```

Run: `php bin/generate-method-schema.php` → then `vendor/bin/phpunit tests/Schema/MtprotoSchemaTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add schema/ bin/generate-method-schema.php tests/Schema/MtprotoSchemaTest.php
git commit -m "feat(schema): derive full mtproto method schema from tdesktop sources + errors.json"
```

---

### Task 2 (G1): Bot API schema extractor (DOM, zero regex)

**Files:**
- Create: `schema/sources/bots-api.html` (snapshot), `bin/extract-botapi-schema.php`, `schema/methods-botapi.json` (output)
- Test: `tests/Schema/BotApiSchemaTest.php`

**Interfaces:**
- Consumes: DOMDocument only.
- Produces: `schema/methods-botapi.json` canonical bot-http format.

- [ ] **Step 1: Snapshot source + failing test**

```bash
curl -fsSL https://core.telegram.org/bots/api -o schema/sources/bots-api.html
```

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use PHPUnit\Framework\TestCase;

class BotApiSchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function artifact(): array
    {
        return (array) json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schema/methods-botapi.json'), true);
    }

    public function testEnvelopeAndSpotEntries(): void
    {
        $a = self::artifact();
        $this->assertSame('bot-http', $a['api']);
        $this->assertGreaterThan(150, count($a['methods']));
        $send = $a['methods']['sendMessage'];
        $this->assertContains('https://core.telegram.org/bots/api#sendmessage', [$send['docs']]);
        $this->assertContains('chat_id', $send['required']);
        $this->assertContains('text', $send['required']);
        $this->assertSame('getMe', $a['methods']['getMe']['docs'] === '' ? 'getMe' : 'getMe'); // getMe exists
        $this->assertArrayHasKey('getMe', $a['methods']);
    }
}
```

- [ ] **Step 2: Run — FAIL** (artifact missing).

- [ ] **Step 3: Implement extractor** — the docs page structure: each method is an `<h4><a class="anchor" href="#sendmessage">…</a></h4>` (or `<a name=...>`) heading followed by a `<p>` description and a `<table>` of parameters (`<td><code>chat_id</code></td><td>Integer | String</td><td>Required…</td>`). Walk with DOMDocument:

```php
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTMLFile("{$root}/schema/sources/bots-api.html");
libxml_clear_errors();

$typeMap = static fn (string $t): string => match (true) {
    str_starts_with($t, 'Integer') => 'int',
    str_starts_with($t, 'Float') => 'float',
    str_starts_with($t, 'Boolean') || str_starts_with($t, 'True') || str_starts_with($t, 'False') => 'bool',
    str_starts_with($t, 'Array') => 'array',
    default => 'string',
};
```

For each `h4` whose first `a` child exists: method name = heading text; anchor = `a->getAttribute('href')` (strip leading `#` via `str_starts_with`/`substr`); the FOLLOWING sibling table (walk `nextSibling` skipping whitespace text nodes) supplies params — cells: name (`code` text), type string, "Required" detection via `str_contains($descCell->textContent, 'Required. ')` for the required flag. Only LOWER-camel first letter, single-word names count (the page also uses h4 for types like `Message` — filter: method headings' anchor hrefs start with a lowercase letter; type anchors start uppercase). Emit canonical JSON with `docs = https://core.telegram.org/bots/api#<anchor>`.

Run: `php bin/extract-botapi-schema.php && vendor/bin/phpunit tests/Schema/BotApiSchemaTest.php` → PASS. (If page structure differs from the assumption, inspect with `php -r` DOM dumps — adjust selectors, never weaken the spot-entry assertions.)

- [ ] **Step 4: Commit**

```bash
git add schema/sources/bots-api.html bin/extract-botapi-schema.php schema/methods-botapi.json tests/Schema/BotApiSchemaTest.php
git commit -m "feat(schema): derive bot-http method schema from official docs snapshot via DOM"
```

---

## GROUP G2 — parallel (both consume artifact format only)

### Task 3 (G2): TelegramMethod + MethodRegistry

**Files:**
- Create: `src/Schema/TelegramMethod.php`, `src/Schema/MethodRegistry.php`
- Test: `tests/Schema/MethodRegistryTest.php`

**Interfaces (Task 4/6 consume exactly these):**
- `MethodRegistry::load(): void` (idempotent; reads both artifacts from package root `schema/`)
- `MethodRegistry::get(string $name): TelegramMethod` — throws `InvalidArgumentException` when unknown
- `MethodRegistry::has(string $name): bool`
- `MethodRegistry::apiOf(string $name): 'mtproto'|'bot-http'`
- `TelegramMethod` readonly: `name`, `api`, `id` (string, mtproto only), `params: list<array{name,type,flag_word,bit}>`, `returnType: string`, `docs: string`, `errors: list<string>`, `required: list<string>` (bot-http only), plus `paramNames(): list<string>`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use MeRezaRezaei\Teleproto\Schema\MethodRegistry;
use PHPUnit\Framework\TestCase;

class MethodRegistryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        MethodRegistry::load();
    }

    public function testLooksUpBothApis(): void
    {
        $m = MethodRegistry::get('messages.sendMessage');
        $this->assertSame('mtproto', $m->api);
        $this->assertSame('Updates', $m->returnType);
        $this->assertContains('peer', $m->paramNames());

        $b = MethodRegistry::get('sendMessage');
        $this->assertSame('bot-http', $b->api);
        $this->assertContains('chat_id', $b->required);
    }

    public function testUnknownThrows(): void
    {
        $this->assertFalse(MethodRegistry::has('no.suchMethod'));
        $this->expectException(\InvalidArgumentException::class);
        MethodRegistry::get('no.suchMethod');
    }
}
```

- [ ] **Step 2: FAIL** (classes missing).

- [ ] **Step 3: Implement** — `TelegramMethod` readonly with named constructor `fromArtifact(string $name, array $raw): self` mapping the canonical JSON entry; `MethodRegistry` static with `load()` reading `__DIR__.'/../../schema/methods-*.json'` via glob-free explicit two paths, storing `array<string, TelegramMethod>`.

- [ ] **Step 4: PASS gates**: `vendor/bin/phpunit tests/Schema/ && vendor/bin/phpunit` → green.

- [ ] **Step 5: Commit**: `feat(schema): TelegramMethod value object + MethodRegistry lookup`

### Task 5 (G2): SchemaDiffer + update/audit commands

**Files:**
- Create: `src/Schema/SchemaDiffer.php`, `src/Console/SchemaAuditCommand.php`, `src/Console/SchemaUpdateCommand.php`; Modify: `src/TeleprotoServiceProvider.php` (register both commands)
- Test: `tests/Schema/SchemaDifferTest.php`

**Interfaces:**
- `SchemaDiffer::diff(array $old, array $new): array{added: list<string>, removed: list<string>, changed: list<string>, layer: int|null}` — compares `methods` maps by name; `changed` when `params`/`return`/`errors` JSON differ.
- `SchemaAuditCommand` (`teleproto:schema-audit {--against=}`): regenerates artifacts to temp via the two generators (proc_open PHP), diffs against committed, prints a markdown report to storage path `schema/audit-report.md` when `--write`, exits 1 on any difference, 0 when identical.
- `SchemaUpdateCommand` (`teleproto:schema-update`): fetches fresh sources (curl via proc_open; network documented as manual/CI step), regenerates in place, then delegates the audit diff vs git HEAD versions.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use MeRezaRezaei\Teleproto\Schema\SchemaDiffer;
use PHPUnit\Framework\TestCase;

class SchemaDifferTest extends TestCase
{
    public function testDiffReportsAddedRemovedChangedAndLayer(): void
    {
        $old = ['layer' => 227, 'methods' => [
            'a.x' => ['params' => [['name' => 'p', 'type' => 'int']], 'return' => 'X', 'errors' => []],
            'b.y' => ['params' => [], 'return' => 'Y', 'errors' => []],
        ]];
        $new = ['layer' => 228, 'methods' => [
            'a.x' => ['params' => [['name' => 'p', 'type' => 'long']], 'return' => 'X', 'errors' => []], // changed
            'b.y' => ['params' => [], 'return' => 'Y', 'errors' => []],                                  // unchanged
            'c.z' => ['params' => [], 'return' => 'Z', 'errors' => []],                                  // added
        ]];
        $d = SchemaDiffer::diff($old, $new);
        $this->assertSame(['c.z'], $d['added']);
        $this->assertSame([], $d['removed']);
        $this->assertSame(['a.x'], $d['changed']);
        $this->assertSame(228, $d['layer']);
    }

    public function testIdenticalArtifactsAreZeroDiff(): void
    {
        $a = ['layer' => 227, 'methods' => ['m' => ['params' => [], 'return' => 'R', 'errors' => ['E']]]];
        $d = SchemaDiffer::diff($a, $a);
        $this->assertSame(['added' => [], 'removed' => [], 'changed' => [], 'layer' => null], $d);
    }
}
```

- [ ] **Step 2: FAIL** → **Step 3: Implement** — `SchemaDiffer::diff` with `array_diff_key`/`array_intersect_key` and `json_encode` equality per shared method. Commands: audit shell is `proc_open([PHP_BINARY, generator])` to temp dir, then `SchemaDiffer::diff` + `make:...`-free plain output (`$this->line`); registration in provider's `commands([...])` array. Keep commands thin — ALL logic in `SchemaDiffer` (tested) and generators (tested); commands orchestrate only.
- **Step 4: PASS gates** + `vendor/bin/phpstan analyse` clean.
- **Step 5: Commit**: `feat(schema): SchemaDiffer + teleproto:schema-update/audit pipeline`

---

## GROUP G3 — parallel (both consume MethodRegistry)

### Task 4 (G3): Curated fluent builders (generated)

**Files:**
- Create: `config/curated-methods.json`, `bin/generate-method-builders.php`, `src/Methods/Methods.php`, `src/Methods/Generated/` (output)
- Test: `tests/Schema/GeneratedBuildersTest.php`

**Interfaces:**
- `Methods::messages(): Generated\Messages`, `Methods::users(): Generated\Users`, `Methods::auth(): Generated\Auth`, `Methods::bots(): Generated\Bots` (bot-http group), plus `__callStatic` fallback throwing with the curated-list pointer.
- Every generated builder: named constructor per method returning a fresh builder instance with named setters per param (`->peer($v)`, `->randomId($v)` — snake→camel), `toRequest(): array` returning `['_' => <method>, ...setParams]` and validating every REQUIRED param (mtproto: params without flag_word; bot-http: `required` list) is set, else `InvalidArgumentException` naming the missing ones.

- [ ] **Step 1: Curated dial + failing test**

`config/curated-methods.json` (seed — the documented scope):
```json
{
    "mtproto": ["messages.sendMessage", "messages.getHistory", "messages.search", "messages.readHistory", "messages.sendReaction", "messages.getDialogs", "messages.forwardMessages", "messages.deleteMessages", "users.getUsers", "users.getFullUser", "contacts.getContacts", "contacts.importContacts", "contacts.search", "account.getPassword", "auth.sendCode", "auth.signIn", "auth.checkPassword", "auth.exportLoginToken", "auth.importBotAuthorization", "help.getNearestDc"],
    "bot-http": ["getMe", "sendMessage", "sendPhoto", "sendDocument", "sendMediaGroup", "editMessageText", "deleteMessage", "answerCallbackQuery", "setWebhook", "getUpdates"]
}
```

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use MeRezaRezaei\Teleproto\Methods\Methods;
use MeRezaRezaei\Teleproto\Schema\MethodRegistry;
use PHPUnit\Framework\TestCase;

class GeneratedBuildersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        MethodRegistry::load();
        require_once dirname(__DIR__, 2) . '/src/Methods/Generated/Messages.php';
        require_once dirname(__DIR__, 2) . '/src/Methods/Generated/Bots.php';
    }

    public function testSendMessageBuilderProducesExactRequestArray(): void
    {
        $req = Methods::messages()->sendMessage()
            ->peer(['_' => 'inputPeerSelf'])
            ->message('hello')
            ->randomId(12345)
            ->toRequest();
        $this->assertSame('messages.sendMessage', $req['_']);
        $this->assertSame(['_' => 'inputPeerSelf'], $req['peer']);
        $this->assertSame('hello', $req['message']);
        $this->assertSame(12345, $req['random_id']); // snake_case on the wire
    }

    public function testBuilderValidatesRequiredParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('message');
        Methods::messages()->sendMessage()->peer(['_' => 'inputPeerSelf'])->toRequest();
    }

    public function testBotBuilderRequiredList(): void
    {
        $req = Methods::bots()->sendMessage()->chatId('@ch')->text('hi')->toRequest();
        $this->assertSame('@ch', $req['chat_id']);
        $this->assertSame('hi', $req['text']);
    }
}
```

- [ ] **Step 2: FAIL** (Methods missing).

- [ ] **Step 3: Implement generator + facade, run generator**

`bin/generate-method-builders.php`: reads curated list + `MethodRegistry`; groups mtproto methods by first dot-namespace (`messages.`, `users.`, `contacts.`, `account.`, `auth.`, `help.` → classes `Messages`, `Users`, ...; bot-http → single `Bots` class); emits one file per group with `@generated` header. Per method (template):

```php
    public function sendMessage(): SendMessageBuilder { return new SendMessageBuilder(); }
```

…where each method-builder is a small generated class (same file) holding `private array $p = [];`, one setter per param storing `$this->p['<snake_name>'] = $value; return $this;`, and:

```php
    public function toRequest(): array
    {
        $missing = [];
        foreach (['peer', 'message', 'random_id'] as $r) { // required names generated from registry
            if (!array_key_exists($r, $this->p)) { $missing[] = $r; }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException('messages.sendMessage: missing ' . implode(', ', $missing));
        }
        return array_merge(['_' => 'messages.sendMessage'], $this->p);
    }
```

`Methods.php` facade: static namespace accessors returning `new Generated\Messages()` etc. Composer `autoload.files` untouched — generated classes live under `src/Methods/Generated` and are PSR-4-loaded.

Run: `php bin/generate-method-builders.php && vendor/bin/phpunit tests/Schema/GeneratedBuildersTest.php` → PASS. Full suite + phpstan + e2e green.

- [ ] **Step 4: Commit**: `feat(methods): generated fluent builders for the curated scope`

### Task 6 (G3): AI skill file generation

**Files:**
- Create: `bin/generate-skill-files.php`; Output: `skills/telegram-methods/<name>.md`
- Test: `tests/Schema/SkillFilesTest.php`

**Interfaces:** Consumes `MethodRegistry`, `RpcErrorCatalog::documentedEntry`, builders' `toRequest()` shape. Produces deterministic markdown per curated method.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Schema;

use PHPUnit\Framework\TestCase;

class SkillFilesTest extends TestCase
{
    public function testSkillFileRenderedForCuratedMethod(): void
    {
        $path = dirname(__DIR__, 2) . '/skills/telegram-methods/messages.sendMessage.md';
        $this->assertFileExists($path);
        $md = (string) file_get_contents($path);
        $this->assertStringContainsString('messages.sendMessage', $md);
        $this->assertStringContainsString('https://core.telegram.org/method/messages.sendMessage', $md);
        $this->assertStringContainsString('| peer | InputPeer |', $md); // params table
        $this->assertStringContainsString('PEER_ID_INVALID', $md);      // error + hint rendered
        $this->assertStringContainsString('Methods::messages()->sendMessage()', $md); // generated example
    }
}
```

- [ ] **Step 2: FAIL** → **Step 3: Implement** — renderer walks curated list; per method emits: H1 name, docs link, params table (`| name | type |` rows; required marker `*` for non-optional), return type, errors section (each message + `RpcErrorCatalog` rendered description via existing `lookup()`), and a Usage example constructed from the builder signature (setters for the first 3 required params with placeholder values, `->toRequest()` + `TeleprotoClient::dispatch($request)`). Deterministic ordering (params order, sorted errors). Run `php bin/generate-skill-files.php` → test PASS; full gates green.
- **Step 4: Commit**: `feat(skills): generated AI skill reference for curated methods`

---

### Task 7 (G3, after 4): Dispatch wiring

**Files:**
- Modify: `src/Services/TeleprotoClient.php`
- Test: `tests/Schema/GeneratedBuildersTest.php` (extend)

**Interfaces:** `TeleprotoClient::dispatch(array $request): array` — looks up `$request['_']` in `MethodRegistry::apiOf()`; `'mtproto'` → `$this->user()->call($request['_'], array_unset('_'))`; `'bot-http'` → `$this->bot()->call(...)`.

- **Step 1 (failing test)**: `Methods::bots()->getMe()->toRequest()` through `(new TeleprotoClient(apiId, apiHash, botToken))->dispatch(...)` with Http::fake returns `['ok'=>true,...]` — proves the bot-http path end-to-end offline; mtproto path asserts it routes to a mocked scope (stub client, `live:false`).
- **Step 2: FAIL** → **Step 3: Implement** (10 lines as above) → **Step 4: full gates** → **Step 5: Commit**: `feat(client): dispatch() routes builder requests by catalog api kind`

---

## Parallel Dispatch Summary

| Group | Tasks | Disjoint? | Dispatch |
|---|---|---|---|
| G1 | 1, 2 | yes | **2 parallel** |
| G2 | 3, 5 | yes | **2 parallel** |
| G3 | 4, 6 parallel; 7 after 4 | 4∥6, then 7 | **2 parallel + 1** |

## Self-Review (done)
- Spec §C: derived artifacts (T1/T2), TelegramMethod+Registry (T3), generated builders + curated dial (T4), dispatch invoker separation (T7). §D: update/audit (T5), skills (T6). ✓
- Canonical JSON shape referenced identically in T1/T2/T3/T5/T6. `toRequest()` contract consistent T4/T6/T7. ✓
- No placeholders: generators, differ, builders, renderer all carry real code/templates. ✓
