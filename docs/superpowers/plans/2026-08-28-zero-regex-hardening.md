# Zero-Regex Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **PARALLEL DISPATCH:** Tasks carry a `Group` tag. Tasks in the SAME group touch disjoint files and MAY be dispatched to parallel implementers simultaneously. Groups must complete in label order: **G1 → G2 → G3**.

**Goal:** Eliminate every `preg_*` from `src/` via deterministic parsers (tokenizer + `sscanf` + string functions + phpseclib), kill undeclared `ctype`, adopt Dotenv — byte-identical behavior proven by unchanged goldens, plus a CI gate that makes regex-creep impossible.

**Architecture:** One new `TLSignatureParser` (character-level tokenizer) becomes the single authority for canonical TL lines, parsed once and cached on `TLRegistry`; scattered per-call regex parsing in encoder/decoder dies. Matchers move to `sscanf`/string functions. PEM handling defers entirely to phpseclib's structured API.

**Tech Stack:** PHP 8.2+, phpseclib3, vlucas/phpdotenv, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-28-hardening-method-layer-design.md` (§A zero-regex, §B hygiene — read first).

## Global Constraints

- `declare(strict_types=1);` in every new/modified file (tests included).
- **Zero `preg_` in `src/` at the end of every task** — Task 6's architecture test enforces it repo-wide; don't introduce new sites anywhere under `src/`.
- Never make PHPUnit hit the network.
- All 110 existing tests + live e2e (`./bin/teleproto test-e2e`, needs `.env`) must pass unchanged after every task — the byte-identical proof.
- `bin/` and `examples/` are regex-exempt (dev scripts).
- One commit per task, message style `refactor(scope): ...` / `feat(scope): ...`.

## File Structure

```
src/MTProto/TL/TLSignatureParser.php        (create — the tokenizer)
src/MTProto/TL/ParsedSignature.php          (create — typed parse result)
src/MTProto/TL/TLRegistry.php               (modify — parse-once cache)
src/MTProto/TL/TLEncoder.php                (modify — consume cached struct, drop fieldsOf regexes)
src/MTProto/TL/TLDecoder.php                (modify — consume cached struct)
src/Exceptions/Rpc/RpcExceptionResolver.php (modify — sscanf)
src/Exceptions/Rpc/RpcErrorCatalog.php      (modify — str template match)
src/MTProto/Crypto/AuthKeyFactory.php       (modify — phpseclib PEM)
src/Support/EnvFile.php                     (modify — Dotenv + str upsert)
src/Console/LoginCommand.php                (modify — Str validation, no ctype)
src/Entities/EntityParser.php               (modify — strspn language check)
tests/Wire/TLSignatureParserTest.php        (create)
tests/Architecture/NoRegexTest.php          (create — gate)
composer.json                               (modify — require vlucas/phpdotenv)
```

---

## GROUP G1 — four independent tasks, dispatch in parallel

### Task 1 (G1): TLSignatureParser — deterministic TL-line tokenizer

**Files:**
- Create: `src/MTProto/TL/ParsedSignature.php`, `src/MTProto/TL/TLSignatureParser.php`
- Test: `tests/Wire/TLSignatureParserTest.php`

**Interfaces:**
- Consumes: nothing (pure).
- Produces (Task 2 + Plan-2 consume):
  - `TLSignatureParser::parse(string $line): ParsedSignature` — throws `InvalidArgumentException("TLSignatureParser: col N: reason")` on malformed input.
  - `ParsedSignature` readonly: `->name: string`, `->id: int` (0 when absent), `->fields: list<array{name: string, type: string, flagWord: ?string, bit: ?int}>`, `->returnType: string`, `->hasExplicitId: bool`.
  - Type normalization identical to today's output: bare `Vector t` becomes `Vector<t>`; conditional types keep the form `flags.N?T` in `type` with `flagWord='flags'`, `bit=N` parsed out; the naked-typename marker `Type` fields are EXCLUDED from `fields` (generic declarations).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\TL\TLSignatureParser;
use PHPUnit\Framework\TestCase;

class TLSignatureParserTest extends TestCase
{
    public function testPlainConstructorWithExplicitId(): void
    {
        $sig = TLSignatureParser::parse('auth.sendCode#a677244f phone_number:string api_id:int api_hash:string settings:CodeSettings = auth.SentCode');
        $this->assertSame('auth.sendCode', $sig->name);
        $this->assertSame(0xa677244f, $sig->id);
        $this->assertTrue($sig->hasExplicitId);
        $this->assertSame('auth.SentCode', $sig->returnType);
        $this->assertCount(4, $sig->fields);
        $this->assertSame(['name' => 'phone_number', 'type' => 'string', 'flagWord' => null, 'bit' => null], $sig->fields[0]);
    }

    public function testIdlessLineComputesNothingAndFlagsZero(): void
    {
        $sig = TLSignatureParser::parse('msgs_ack msg_ids:Vector<long> = MsgsAck');
        $this->assertSame('msgs_ack', $sig->name);
        $this->assertFalse($sig->hasExplicitId);
        $this->assertSame('Vector<long>', $sig->fields[0]['type']);
    }

    public function testConditionalFieldParsesFlagWordAndBit(): void
    {
        $sig = TLSignatureParser::parse('x#deadbeef f:flags.0?string flags:# = X');
        // 'flags' word itself must appear as a field with type '#'
        $this->assertSame(['name' => 'f', 'type' => 'string', 'flagWord' => 'flags', 'bit' => 0], $sig->fields[0]);
        $this->assertSame(['name' => 'flags', 'type' => '#', 'flagWord' => null, 'bit' => null], $sig->fields[1]);
    }

    public function testBareVectorTwoTokenFormNormalizes(): void
    {
        $sig = TLSignatureParser::parse('help.getNearestDc = NearestDc');
        $this->assertSame([], $sig->fields);
        $this->assertSame('NearestDc', $sig->returnType);
        $sig2 = TLSignatureParser::parse('users.getUsers#d91a548 id:Vector InputUser = Vector User');
        // bare two-token Vector: `Vector InputUser` -> Vector<InputUser>; trailing return `Vector User` -> Vector<User>
        $this->assertSame('Vector<InputUser>', $sig2->fields[0]['type']);
        $this->assertSame('Vector<User>', $sig2->returnType);
    }

    public function testSecondFlagWord(): void
    {
        $sig = TLSignatureParser::parse('u#1 flags:# flags2:# last:flags2.0?string = User');
        $this->assertSame('flags2', $sig->fields[2]['flagWord']);
        $this->assertSame(0, $sig->fields[2]['bit']);
    }

    public function testMalformedLinesThrowWithColumn(): void
    {
        foreach ([
            'name#zz bad input' => 'id',
            'name field-without-col = X' => "':'",
            'name = ' => 'return',
            '' => 'name',
        ] as $line => $needle) {
            try {
                TLSignatureParser::parse($line);
                $this->fail("no exception for: {$line}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('col ', $e->getMessage(), $line);
                $this->assertStringContainsString($needle, $e->getMessage(), $line);
            }
        }
    }
}
```

- [ ] **Step 2: Run — verify failure**

Run: `vendor/bin/phpunit tests/Wire/TLSignatureParserTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`ParsedSignature.php`:

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

/**
 * Immutable parse result of one canonical TL line. Built only by TLSignatureParser.
 *
 * @phpstan-type SigField array{name: string, type: string, flagWord: string|null, bit: int|null}
 */
final class ParsedSignature
{
    /**
     * @param list<SigField> $fields
     */
    public function __construct(
        public readonly string $name,
        public readonly int $id,
        public readonly bool $hasExplicitId,
        public readonly array $fields,
        public readonly string $returnType,
    ) {}
}
```

`TLSignatureParser.php` (character-level cursor — no regex anywhere):

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

use InvalidArgumentException;

/**
 * Deterministic tokenizer for canonical TL schema lines:
 *   name[#id] field:type [field2:flags.N?Type ...] = ReturnType
 * Malformed input throws with the exact column and reason.
 */
final class TLSignatureParser
{
    public static function parse(string $line): ParsedSignature
    {
        $line = trim($line);
        $len = strlen($line);
        $col = 0;

        $name = self::takeWhile($line, $col, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.');
        if ($name === '') {
            throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected constructor name");
        }

        $id = 0;
        $hasId = false;
        if ($col < $len && $line[$col] === '#') {
            $col++;
            $hex = self::takeWhile($line, $col, '0123456789abcdefABCDEF');
            if ($hex === '') {
                throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected hex id after '#'");
            }
            $id = (int) hexdec($hex);
            $hasId = true;
        }

        /** @var list<array{name: string, type: string, flagWord: string|null, bit: int|null}> $fields */
        $fields = [];
        $returnType = '';

        // fields until '='
        while ($col < $len && $line[$col] !== '=') {
            self::skipSpaces($line, $col);
            if ($col >= $len || $line[$col] === '=') {
                break;
            }
            $fName = self::takeWhile($line, $col, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_');
            if ($fName === '') {
                throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected field name");
            }
            if ($col >= $len || $line[$col] !== ':') {
                throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected ':' after field name '{$fName}'");
            }
            $col++;
            [$type, $flagWord, $bit] = self::parseType($line, $col);

            // generic declaration `{X:Type}` arrives here as type 'Type' — skip, not a wire field
            if ($type !== 'Type') {
                $fields[] = ['name' => $fName, 'type' => $type, 'flagWord' => $flagWord, 'bit' => $bit];
            }
        }

        if ($col >= $len || $line[$col] !== '=') {
            throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected '=' before return type");
        }
        $col++;
        self::skipSpaces($line, $col);
        $returnType = self::parseReturnType($line, $col);
        if ($returnType === '') {
            throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected return type after '='");
        }

        return new ParsedSignature($name, $id, $hasId, $fields, $returnType);
    }

    /**
     * Parses one field type token; handles `flags.N?T` and the bare
     * two-token `Vector t` form. Returns [normalizedType, flagWord|null, bit|null].
     *
     * @return array{0: string, 1: string|null, 2: int|null}
     */
    private static function parseType(string $line, int &$col): array
    {
        $len = strlen($line);
        $word = self::takeWhile($line, $col, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.');
        if ($word === '') {
            throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected type token");
        }

        // conditional: flagsWord.N?T
        if ($col < $len && $line[$col] === '.') {
            $col++;
            $digits = self::takeWhile($line, $col, '0123456789');
            if ($digits === '' || $col >= $len || $line[$col] !== '?') {
                throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected 'N?' after '{$word}.'");
            }
            $col++;
            [$inner, ,] = self::parseType($line, $col);
            return [$inner, $word, (int) $digits];
        }

        // bare Vector two-token form: `Vector t` -> Vector<t>
        if ($word === 'Vector') {
            $peek = $col;
            self::skipSpaces($line, $peek);
            $inner = self::takeWhile($line, $peek, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.');
            if ($inner !== '') {
                $col = $peek;
                return ['Vector<' . $inner . '>', null, null];
            }
            return ['Vector', null, null];
        }

        return [$word, null, null];
    }

    /** Return type may itself be the bare `Vector t` form. */
    private static function parseReturnType(string $line, int &$col): string
    {
        [$type, ,] = self::parseType($line, $col);
        return $type;
    }

    private static function takeWhile(string $line, int &$col, string $allowed): string
    {
        $start = $col;
        $len = strlen($line);
        while ($col < $len && str_contains($allowed, $line[$col])) {
            $col++;
        }
        return substr($line, $start, $col - $start);
    }

    private static function skipSpaces(string $line, int &$col): void
    {
        $len = strlen($line);
        while ($col < $len && ($line[$col] === ' ' || $line[$col] === "\t")) {
            $col++;
        }
    }
}
```

- [ ] **Step 4: Run — verify pass**

Run: `vendor/bin/phpunit tests/Wire/TLSignatureParserTest.php`
Expected: PASS (6 tests). If `users.getUsers#d91a548 id:Vector InputUser = Vector User` style ever appears with angle brackets already (`Vector<User>`), the `<`/`>` chars simply aren't consumed by `takeWhile` and the two-token branch does not trigger — behavior matches the old normalizer.

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/TL/TLSignatureParser.php src/MTProto/TL/ParsedSignature.php tests/Wire/TLSignatureParserTest.php
git commit -m "feat(tl): deterministic TLSignatureParser with column-precise errors"
```

---

### Task 3 (G1): Resolver + Catalog — sscanf and string matching

**Files:**
- Modify: `src/Exceptions/Rpc/RpcExceptionResolver.php` (2 preg sites), `src/Exceptions/Rpc/RpcErrorCatalog.php` (2 preg sites)
- Test: `tests/Wire/RpcExceptionResolverTest.php` (extend; existing 9 tests must stay green untouched)

**Interfaces:**
- Consumes: existing `RpcErrorCatalog::DESCRIPTIONS` (templates with `%d`).
- Produces: identical public behavior. New private `RpcErrorCatalog::templateMatches(string $template, string $message): bool` — pure, testable indirectly via `lookup()`.

- [ ] **Step 1: Write the failing tests** (append to `RpcExceptionResolverTest`)

```php
    public function testParameterizedMatchesWorkWithoutRegex(): void
    {
        foreach (['FLOOD_WAIT_1', 'FLOOD_WAIT_999999', 'FLOOD_PREMIUM_WAIT_7'] as $msg) {
            $this->assertStringContainsString('wait', RpcExceptionResolver::resolve($msg)->getMessage(), $msg);
        }
        foreach (['PHONE_MIGRATE_4', 'USER_MIGRATE_5', 'NETWORK_MIGRATE_1', 'FILE_MIGRATE_2'] as $msg) {
            $this->assertInstanceOf(DcMigrationException::class, RpcExceptionResolver::resolve($msg), $msg);
        }
        // malformed variants must NOT match any template
        $this->assertSame('FLOOD_WAIT_X', RpcExceptionResolver::resolve('FLOOD_WAIT_X')->rpcErrorMessage);
        $this->assertSame('FLOOD_WAIT_', RpcExceptionResolver::resolve('FLOOD_WAIT_')->rpcErrorMessage);
        $this->assertNull(\MeRezaRezaei\Teleproto\Exceptions\Rpc\RpcErrorCatalog::lookup('SLOWMODE_WAIT_3O')); // letter O, not zero
    }
```

- [ ] **Step 2: Run — verify failure**

Run: `vendor/bin/phpunit tests/Wire/RpcExceptionResolverTest.php`
Expected: FAIL — `SLOWMODE_WAIT_3O` currently matches nothing but the test asserts null on lookup… it should already return null; instead the *new* failing point is none. If it passes trivially, strengthen: temporarily assert `lookup('SLOWMODE_WAIT_30')` returns non-null first (sanity), keep the `3O` null assertion. The regex-free refactor in Step 3 must leave both behaviors identical — that is the test's real job: pinning edge behavior across the refactor.

- [ ] **Step 3: Implement**

In `RpcExceptionResolver::resolve`, replace both `preg_match` blocks:

```php
        // FLOOD_WAIT_X / FLOOD_PREMIUM_WAIT_X — sscanf, no regex
        $seconds = 0;
        foreach (['FLOOD_WAIT_%d', 'FLOOD_PREMIUM_WAIT_%d'] as $fmt) {
            if (sscanf($message, $fmt . '%c', $seconds, $c) === 1) { // trailing %c rejects suffixes
                return new FloodWaitException($seconds, $message, $errorCode);
            }
        }

        // FILE/PHONE/NETWORK/USER_MIGRATE_X
        $dc = 0;
        foreach (['FILE_MIGRATE_', 'PHONE_MIGRATE_', 'NETWORK_MIGRATE_', 'USER_MIGRATE_'] as $pfx) {
            if (str_starts_with($message, $pfx)
                && sscanf($message, $pfx . '%d%c', $dc, $c) === 1
                && $dc > 0 && $dc <= 5) {
                return new DcMigrationException($dc, "{$message} — repeat the request at DC {$dc} (per https://core.telegram.org/api/datacenter)", $errorCode);
            }
        }
```

Declare `int $c` / uninitialized — initialize `$c = "\0"` before each use loop to avoid phpstan complaints.

In `RpcErrorCatalog::lookup`, replace the regex template loop:

```php
        foreach (self::templates() as $template => $descTemplate) {
            if (self::templateMatches($template, $msg)) {
                $value = self::templateValue($template, $msg);
                return [$template, str_contains($descTemplate, '%d') ? sprintf($descTemplate, $value) : $descTemplate];
            }
        }
        return null;
```

with two tiny pure helpers (same file):

```php
    private static function templateMatches(string $template, string $message): bool
    {
        $parts = explode('%d', $template); // e.g. ['SLOWMODE_WAIT_', '']
        if (!str_starts_with($message, $parts[0])) {
            return false;
        }
        $tail = substr($message, strlen($parts[0]));
        $requiredSuffix = $parts[1] ?? '';
        if (!str_ends_with($tail, $requiredSuffix)) {
            return false;
        }
        $digits = substr($tail, 0, strlen($tail) - strlen($requiredSuffix));
        return $digits !== '' && strspn($digits, '0123456789') === strlen($digits);
    }

    private static function templateValue(string $template, string $message): int
    {
        $parts = explode('%d', $template);
        $tail = substr($message, strlen($parts[0]));
        return (int) substr($tail, 0, strlen($tail) - strlen($parts[1] ?? ''));
    }
```

- [ ] **Step 4: Run — full catalog suite green**

Run: `vendor/bin/phpunit tests/Wire/RpcExceptionResolverTest.php && vendor/bin/phpunit`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add src/Exceptions/Rpc/RpcExceptionResolver.php src/Exceptions/Rpc/RpcErrorCatalog.php tests/Wire/RpcExceptionResolverTest.php
git commit -m "refactor(rpc): sscanf + string template matching, zero regex"
```

---

### Task 4 (G1): AuthKeyFactory — phpseclib-structured PEM handling

**Files:**
- Modify: `src/MTProto/Crypto/AuthKeyFactory.php` (5 preg sites: `pkcs1DerOf` DER scraping)
- Test: `tests/Wire/AuthKeyFactoryOfflineTest.php` (extend)

**Interfaces:**
- Consumes: phpseclib `PublicKeyLoader`, `RSA\PublicKey::toString('PKCS1')`.
- Produces: unchanged `AuthKeyFactory::fingerprintOf/pkcs1DerOf` signatures. Multi-PEM bundle still selects the FIRST key.

- [ ] **Step 1: Write the failing test** (append to the offline test — must pass BOTH before and after refactor: it pins behavior)

```php
    public function testFingerprintOfEachBundledKeyStaysStable(): void
    {
        $bundle = file_get_contents(__DIR__ . '/../../src/MTProto/resources/telegram_public_key.pub');
        $this->assertNotFalse($bundle);
        $fps = [];
        foreach (explode('-----END RSA PUBLIC KEY-----', (string) $bundle) as $chunk) {
            if (!str_contains($chunk, 'BEGIN')) {
                continue;
            }
            $pem = $chunk . '-----END RSA PUBLIC KEY-----';
            $fps[] = sprintf('%016x', AuthKeyFactory::fingerprintOf($pem));
        }
        $this->assertSame(['05fd64de851d9dd0', '03268d20df9858b2'], $fps); // official transcript + test-DC keys
    }
```

- [ ] **Step 2: Run** — `vendor/bin/phpunit tests/Wire/AuthKeyFactoryOfflineTest.php` — Expected: PASS (pins current behavior before refactor).

- [ ] **Step 3: Implement** — replace the regex-scraping `pkcs1DerOf` body with structured conversion:

```php
    /**
     * Normalizes any supported public key input (PKCS#1 PEM, SPKI PEM, DER)
     * to the PKCS#1 RSAPublicKey DER via phpseclib — no byte scraping.
     */
    protected static function pkcs1DerOf(string $pem): string
    {
        $key = \phpseclib3\Crypt\PublicKeyLoader::load($pem);
        $pkcs1Pem = $key->toString('PKCS1');
        $body = '';
        foreach (explode("\n", $pkcs1Pem) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '-----')) {
                continue;
            }
            $body .= $line;
        }
        return (string) base64_decode($body, true);
    }
```

(Keep the multi-key bundle selection logic — callers pass a single PEM slice; if `generate()` currently extracts the first PEM via regex, replace that extraction with the same line-walk over the bundle: accumulate lines between the first `-----BEGIN` and its `-----END`.)

- [ ] **Step 4: Run — transcript vectors + suite**

Run: `vendor/bin/phpunit tests/Wire/AuthKeyFactoryOfflineTest.php && vendor/bin/phpunit`
Expected: PASS — the transcript decrypt tests are the byte-identical proof.

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/Crypto/AuthKeyFactory.php tests/Wire/AuthKeyFactoryOfflineTest.php
git commit -m "refactor(crypto): phpseclib-structured PEM normalization, zero regex"
```

---

### Task 5 (G1): EnvFile + LoginCommand + EntityParser — string functions, Dotenv, ctype kill

**Files:**
- Modify: `src/Support/EnvFile.php`, `src/Console/LoginCommand.php`, `src/Entities/EntityParser.php`, `composer.json`, `composer.lock`
- Test: `tests/Support/EnvFileTest.php` (create), `tests/TeleprotoClientTest.php` (extend one test)

**Interfaces:**
- Consumes: `Dotenv::parse` from `vlucas/phpdotenv`; `Illuminate\Support\Str`.
- Produces: `EnvFile::read/upsert` unchanged signatures.

- [ ] **Step 1: Failing test**

`tests/Support/EnvFileTest.php`:

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Support;

use MeRezaRezaei\Teleproto\Support\EnvFile;
use PHPUnit\Framework\TestCase;

class EnvFileTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'envtest');
        file_put_contents($this->tmp, "# comment\nA=1\nB=\"quoted value\"\n\nC=\"old\"\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
    }

    public function testReadParsesValuesIncludingQuoted(): void
    {
        $this->assertSame('1', EnvFile::read($this->tmp)['A'] ?? '');
        $this->assertSame('quoted value', EnvFile::read($this->tmp)['B'] ?? '');
    }

    public function testUpsertReplacesExistingAndAddsNew(): void
    {
        EnvFile::upsert($this->tmp, 'C', 'new');
        EnvFile::upsert($this->tmp, 'D', 'added');
        $vars = EnvFile::read($this->tmp);
        $this->assertSame('new', $vars['C']);
        $this->assertSame('added', $vars['D']);
        $this->assertSame('1', $vars['A']); // untouched lines survive
    }

    public function testReadMissingFileReturnsEmpty(): void
    {
        $this->assertSame([], EnvFile::read('/nonexistent/env'));
    }
}
```

- [ ] **Step 2: Run — verify failure** (`EnvFileTest` new file: `testReadParsesValuesIncludingQuoted` may pass already — the pinning matters for the Dotenv swap; `vendor/bin/phpunit tests/Support/EnvFileTest.php`, then proceed).

- [ ] **Step 3: Implement**

`composer require vlucas/phpdotenv:^5.6 --quiet` then rewrite `EnvFile`:

```php
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

    public static function upsert(string $path, string $key, string $value): void
    {
        $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        $prefix = $key . '=';
        $found = false;
        foreach ($lines as $i => $line) {
            if (str_starts_with(ltrim($line), $prefix)) {
                $lines[$i] = $key . '="' . $value . '"';
                $found = true;
                break;
            }
        }
        if (!$found) {
            $lines[] = $key . '="' . $value . '"';
        }
        file_put_contents($path, implode("\n", $lines) . "\n");
    }
}
```

`LoginCommand` validation closures (replace `ctype_digit` and the phone regex):

```php
        $apiId = (int) (config('teleproto.api_id') ?: text(
            'Telegram API ID',
            placeholder: 'from https://my.telegram.org',
            validate: fn (string $v) => ($v !== '' && strspn($v, '0123456789') === strlen($v) && (int) $v > 0) ? null : 'API ID must be a positive integer.'
        ));
        // phone:
        validate: fn (string $v) => (\Illuminate\Support\Str::startsWith($v, '+') && strlen($v) > 8 && strspn(substr($v, 1), '0123456789') === strlen($v) - 1) ? null : 'Use full international format, e.g. +989123456789.'
```

`EntityParser`: locate the code-fence language check (currently `preg_match('/^[a-zA-Z0-9_-]+$/', $firstLine)`) and replace with:

```php
$ok = $firstLine !== '' && strspn($firstLine, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-') === strlen($firstLine);
if ($ok) { /* treat as language, strip */ }
```

(The markdown lexer test `testMarkdownEscapedCharactersAndPreBlocks` pins this behavior — must stay green.)

- [ ] **Step 4: Run — targeted + full**

Run: `vendor/bin/phpunit tests/Support/EnvFileTest.php tests/TeleprotoClientTest.php && vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(support): Dotenv parsing, string-only env upsert + validation, ctype retired"
```

---

## GROUP G2 — after G1/Task 1 completes

### Task 2 (G2): Registry + codecs consume the cached ParsedSignature

**Files:**
- Modify: `src/MTProto/TL/TLRegistry.php`, `src/MTProto/TL/TLEncoder.php`, `src/MTProto/TL/TLDecoder.php`
- Test: `tests/Wire/TLRegistryTest.php`, `tests/Wire/TLCodecTest.php`, `tests/Wire/FlagWordsTest.php` (all existing must pass UNCHANGED — byte-identical proof)

**Interfaces:**
- Consumes: `TLSignatureParser::parse`, `ParsedSignature` (Task 1).
- Produces: `TLRegistry::signatureOf(string $name): ParsedSignature` (new). Existing `id()/signature()/nameOf()/register()` unchanged externally. `TLEncoder::fieldsOf` becomes a thin wrapper (kept for BC with tests) returning `list<array{0:string,1:string}>` derived from the cached struct.

- [ ] **Step 1: Write the failing test** (append to `TLRegistryTest`)

```php
    public function testSignatureOfReturnsParsedStructWithCache(): void
    {
        $sig = TLRegistry::signatureOf('auth.sendCode');
        $this->assertSame('auth.sendCode', $sig->name);
        $this->assertSame(0xa677244f, $sig->id);
        $this->assertSame('phone_number', $sig->fields[0]['name']);
        // second call returns the SAME instance (parsed once)
        $this->assertSame($sig, TLRegistry::signatureOf('auth.sendCode'));
    }
```

- [ ] **Step 2: Run — verify failure** (`signatureOf` undefined).

- [ ] **Step 3: Implement**

`TLRegistry`: keep the static arrays; on `register($line)` store `self::$parsed[$name] = TLSignatureParser::parse($line)`; compute id via `$parsed->hasExplicitId ? $parsed->id : self::crc32Canonical($line)`. Add:

```php
    public static function signatureOf(string $name): \MeRezaRezaei\Teleproto\MTProto\TL\ParsedSignature
    {
        self::boot();
        return static::$parsed[$name] ?? throw new \InvalidArgumentException("TLRegistry: unknown constructor '{$name}'");
    }
```

`TLEncoder::encodeObject`: replace `fieldsOf()` + regex flag handling with the cached struct — iterate `$sig->fields`; when `$field['flagWord'] !== null` consult that flag word's value; skip absent; write `encodeValue($field['type'], ...)` for present. `fieldsOf(string $signature): array` stays as a BC wrapper that runs `TLSignatureParser::parse($signature)` and maps to `[name, type]` pairs (used by tests and `TLDecoder` until rewired below).

`TLDecoder::decodeObject`: same replacement — drop the `preg_match('/^([a-zA-Z0-9_]+)\.(\d+)\?(.+)$/` flag walk; use `signatureOf($name)->fields` with `flagWord`/`bit`.

Delete the now-unused `preg_replace('/:Vector .../)` normalization and the `#`-stripping regex from both classes (the tokenizer normalizes).

- [ ] **Step 4: Run — the byte-identical gate**

Run: `vendor/bin/phpunit` (full suite) AND `./bin/teleproto test-e2e`
Expected: ALL PASS unchanged — goldens, transcript vectors, flag-words, containers.

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/TL/ && git commit -m "refactor(tl): parse-once ParsedSignature cache drives encoder/decoder"
```

---

## GROUP G3 — after G1 + G2

### Task 6 (G3): The no-regex static-analysis gate

**Reinvention audit outcome:** a maintained PHPStan extension does this better than any hand-rolled test — **`spaze/phpstan-disallowed-calls`** (MIT, 690+ commits) forbids function calls statically, across *all* code paths, not just those tests execute.

**Files:**
- Modify: `composer.json`, `composer.lock`, `phpstan.neon.dist`
- Delete: none (the previously drafted `tests/Architecture/NoRegexTest.php` is NOT created — superseded)

**Interfaces:** Produces: `composer analyse` fails on any `preg_*` call under `src/` with a custom message; `bin/` + `examples/` exempted.

- [ ] **Step 1: Install + configure**

```bash
composer require --dev spaze/phpstan-disallowed-calls --quiet
```

Append to `phpstan.neon.dist`:

```neon
    excludesPaths:
        - bin
        - examples
includes:
    - vendor/spaze/phpstan-disallowed-calls/extension.neon
parameters:
    disallowedFunctionCalls:
        -
            function: 'preg_.+'
            message: 'src/ is regex-free by spec 2026-08-28 §A — use sscanf/string functions/the TL tokenizer'
```

(If `excludesPaths` placement fights the existing config, use the extension's `allowInPaths` on `bin/*`, `examples/*` instead — both documented in the extension README.)

- [ ] **Step 2: Verify the gate fires**

Temporary: add `preg_match('/x/','x');` to any src file → `vendor/bin/phpstan analyse --no-progress` must report the disallowed call with the custom message. Remove the sabotage line afterwards. Run again → `[OK] No errors`.

- [ ] **Step 3: Full gates**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress && ./bin/teleproto test-e2e`
Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock phpstan.neon.dist
git commit -m "feat(arch): forbid preg_* via phpstan-disallowed-calls per hardening spec"
```

---

## Reinvention-Audit Addendum (2026-08-28, pre-execution)

| Plan component | Existing tool | Ruling |
|---|---|---|
| No-regex gate (was: hand-rolled string-scan test) | **`spaze/phpstan-disallowed-calls`** (MIT, 690+ commits) | **ADOPT** — Task 6 rewritten around the static rule (all code paths, custom message, path exemptions) |
| TL parser / sscanf / phpseclib / Dotenv choices | searched Context7 + packagist | No standalone PHP TL parser exists (confirmed niche — tokenizer stays ours); other choices already stable-lib maximal |

## Parallel Dispatch Summary

| Group | Tasks | Disjoint files? | Dispatch |
|---|---|---|---|
| G1 | 1, 3, 4, 5 | yes | **4 parallel implementers** |
| G2 | 2 | depends on Task 1's parser | single |
| G3 | 6 | depends on all | single, final gate |

## Self-Review (done)
- Spec §A coverage: 19 sites → Task 1 (TL 9), Task 3 (4), Task 4 (5), Task 5 (2+ctype), Task 6 gate. ✓
- §B: Dotenv (T5), Str (T5), ext audit (T5 kills ctype; declared set verified unchanged). ✓
- Type consistency: `ParsedSignature->fields` shape `SigField` used identically in T1/T2. `signatureOf` name consistent. ✓
- No placeholders: every step carries code. ✓
