# MTProto Real Wire Path Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `teleproto` actually speak MTProto 2.0 to real Telegram DCs — TCP framing, auth-key handshake, and real RPC calls — verified live by a `teleproto:doctor` command, while keeping all existing offline tests green.

**Architecture:** A layered wire path: `FrameCodec` (TCP intermediate framing) wraps `StreamSocket`; `TLRegistry` maps constructor names → CRC32 ids from canonical schema strings; `AuthKeyFactory` runs the DH handshake over an unencrypted `PlainConnection`; `Connection` performs encrypted RPC (MTProto 2.0 envelopes via existing `PacketCodec`); `MTProto\Client::call()` gains a `live` mode that is opt-in (env `TELEPROTO_LIVE=1`) so the current offline stub — and every existing test — keeps working. Live verification runs through a new `teleproto:doctor` command which needs **no Telegram account** (key exchange + `help.getNearestDc` + optional bot MTProto login).

**Tech Stack:** PHP 8.2+, ext-openssl/hash/mbstring/zlib, phpseclib3 (BigInteger, RSA), Laravel illuminate/console, PHPUnit 10/11.

**Spec:** `docs/superpowers/specs/2026-08-28-mtproto-wire-path-design.md` (read it first — it justifies every decision below) + `docs/superpowers/specs/2026-08-27-teleproto-core-design.md` (package boundaries).

## Global Constraints

- PHP >= 8.2, `declare(strict_types=1);` in every new file.
- Zero new runtime composer packages; new ext requirement `ext-zlib` in composer.json. Dev-only `larastan/larastan` allowed.
- Every user-facing message/method/PHPDoc uses "Teleproto"/"TP" naming — never "Telegram" facade (package rule).
- Public API style: static helpers on small final-value classes, plain `array<string,mixed>` in/out — no DTOs, no Eloquent.
- MTProto layer constant: 227. Default DC: 2 (`149.154.167.51:443`).
- Existing tests must stay green without network access; all live behavior is behind `live` flags/env. **Never make PHPUnit hit the network.**
- Commit after every green test cycle; message style matches `feat(scope):`, `fix(scope):`, `test(scope):`, `chore(scope):`.
- If a golden constructor-ID test fails, the canonical schema string has a typo — fix the string, not the expected constant (published IDs are authoritative).

## File Structure

```
src/MTProto/
  Transport/StreamSocket.php        (modify: remove inert proxy block, add read/write helpers)
  Transport/FrameCodec.php          (create: 0xee intermediate framing over a socket)
  TL/TLRegistry.php                 (create: name↔id from canonical schema strings)
  TL/TLEncoder.php                  (create: serialize plain PHP arrays per schema string)
  TL/TLDecoder.php                  (create: parse TL objects per schema string)
  Crypto/PqFactorizer.php           (create: Pollard rho; canonical home for pq factorization)
  Crypto/AuthKeyFactory.php         (create: full DH handshake → SessionData with authKey)
  Connection/PlainConnection.php    (create: length+body writer/reader for handshake)
  Connection/EncryptedConnection.php(create: salt/session envelope, gzip_packed, rpc_result)
  Client.php                        (modify: `live` mode wiring)
  resources/telegram_public_key.pub (create: fetched from core.telegram.org)
src/Console/DoctorCommand.php       (create)
config/teleproto.php                (modify: live_mode)
tests/Wire/                          (create: all new offline tests)
```

---

### Task 1: StreamSocket cleanup + I/O helpers

**Files:**
- Modify: `src/MTProto/Transport/StreamSocket.php`
- Test: `tests/Wire/StreamSocketTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `StreamSocket::createConnection(string $host, int $port = 443, ?array $proxy = null, float $timeout = 10.0): resource` (unchanged signature); NEW `StreamSocket::write($socket, string $bytes): void`; NEW `StreamSocket::read($socket, int $length): string` (throws `RuntimeException` on EOF/short read); NEW `StreamSocket::readExact($socket, int $length): string` (loops until filled).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;
use PHPUnit\Framework\TestCase;

class StreamSocketTest extends TestCase
{
    public function testWriteAndReadExactOverLoopbackEcho(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "loopback server failed: $errstr");
        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $client = StreamSocket::createConnection($host, (int)$port, timeout: 5.0);
        $accepted = stream_socket_accept($server, 5.0);

        StreamSocket::write($client, "hello-frame-bytes");

        // echo server: read then write back
        $got = fread($accepted, 16);
        fwrite($accepted, $got);

        $this->assertSame('hello-frame-bytes', StreamSocket::readExact($client, 17));
        fclose($client);
        fclose($accepted);
        fclose($server);
    }

    public function testReadThrowsOnPrematureEof(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('EOF');
        // A closed pipe resource: stream_socket_pair is simplest
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fclose($b);
        StreamSocket::readExact($a, 64);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Wire/StreamSocketTest.php`
Expected: FAIL — `write`/`readExact` undefined.

- [ ] **Step 3: Write minimal implementation**

Replace the inert proxy block and add helpers in `StreamSocket`:

```php
    public static function write($socket, string $bytes): void
    {
        $total = strlen($bytes);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($socket, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('StreamSocket: write failed at offset ' . $written);
            }
            $written += $n;
        }
    }

    public static function read($socket, int $length): string
    {
        $chunk = @fread($socket, $length);
        if ($chunk === false || $chunk === '') {
            throw new \RuntimeException('StreamSocket: EOF while reading');
        }
        return $chunk;
    }

    public static function readExact($socket, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $buffer .= self::read($socket, $length - strlen($buffer));
        }
        return $buffer;
    }
```

Delete the `if (!empty($proxy['host']...` block from `createConnection` (it wrote to a `http` context on a TCP socket — inert). Keep the `$proxy` parameter (unused for now) so the public signature stays stable; add a docblock line `@todo proxy tunneling is not implemented yet; connection is direct`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Wire/StreamSocketTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/Transport/StreamSocket.php tests/Wire/StreamSocketTest.php
git commit -m "feat(transport): add stream write/readExact helpers and drop inert proxy context"
```

---

### Task 2: FrameCodec — intermediate TCP transport (0xee)

**Files:**
- Create: `src/MTProto/Transport/FrameCodec.php`
- Test: `tests/Wire/FrameCodecTest.php`

**Interfaces:**
- Consumes: `StreamSocket::write/readExact` from Task 1.
- Produces: `FrameCodec::wrapPayload(string $payload): string` (4-byte LE length + payload), `FrameCodec::sendMessage($socket, string $payload): void` (writes wrapPayload), `FrameCodec::receiveMessage($socket): string` (reads length, rejects > 2MB, reads body), `FrameCodec::writeInit($socket): void` (writes single `0xef` byte — intermediate-transport magic).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;
use PHPUnit\Framework\TestCase;

class FrameCodecTest extends TestCase
{
    public function testWrapPayloadUsesLittleEndianLengthPrefix(): void
    {
        $wrapped = FrameCodec::wrapPayload('ABCD');
        $this->assertSame(4, unpack('V', substr($wrapped, 0, 4))[1]);
        $this->assertSame('ABCD', substr($wrapped, 4));
    }

    public function testFrameRoundTripOverLoopback(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $client = StreamSocket::createConnection($host, (int)$port, timeout: 5.0);
        $accepted = stream_socket_accept($server, 5.0);

        FrameCodec::writeInit($client);
        FrameCodec::sendMessage($client, 'hello-transport');
        // server side: read 1 init byte, one length, echo the payload back
        fread($accepted, 1);
        $len = unpack('V', StreamSocket::readExact($accepted, 4))[1];
        $payload = StreamSocket::readExact($accepted, $len);
        FrameCodec::sendMessage($accepted, $payload);

        $this->assertSame('hello-transport', FrameCodec::receiveMessage($client));
        fclose($client); fclose($accepted); fclose($server);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Wire/FrameCodecTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Transport;

use RuntimeException;

/**
 * MTProto intermediate TCP transport: single 0xef init byte, then
 * each message framed as 4-byte little-endian length + payload.
 */
class FrameCodec
{
    private const MAX_FRAME = 2 * 1024 * 1024;

    public static function writeInit($socket): void
    {
        StreamSocket::write($socket, "\xef");
    }

    public static function wrapPayload(string $payload): string
    {
        return pack('V', strlen($payload)) . $payload;
    }

    public static function sendMessage($socket, string $payload): void
    {
        StreamSocket::write($socket, self::wrapPayload($payload));
    }

    public static function receiveMessage($socket): string
    {
        $len = unpack('V', StreamSocket::readExact($socket, 4))[1];
        if ($len < 1 || $len > self::MAX_FRAME) {
            throw new RuntimeException("FrameCodec: invalid frame length {$len}");
        }
        return StreamSocket::readExact($socket, $len);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Wire/FrameCodecTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/Transport/FrameCodec.php tests/Wire/FrameCodecTest.php
git commit -m "feat(transport): add FrameCodec intermediate 0xee TCP framing"
```

---

### Task 3: TLRegistry — constructor IDs from canonical schema

**Files:**
- Create: `src/MTProto/TL/TLRegistry.php`
- Test: `tests/Wire/TLRegistryTest.php`

**Interfaces:**
- Consumes: nothing (pure PHP `crc32`).
- Produces: `TLRegistry::id(string $constructorName): int` (id of the constructor's *own* line, e.g. `'req_pq_multi'`); `TLRegistry::signature(string $constructorName): string` (full canonical line); `TLRegistry::register(string $canonicalLine): void`; constant `TLRegistry::VECTOR = 0x1cb5c415`. Registry pre-seeded with the SCHEMA constant below. Throws `InvalidArgumentException` for unknown names.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use PHPUnit\Framework\TestCase;

class TLRegistryTest extends TestCase
{
    public function testGoldenConstructorIds(): void
    {
        // If one of these fails, the canonical string in the registry has a typo;
        // the published ID is authoritative — fix the string, not this test.
        $goldens = [
            'req_pq_multi' => 0x778e4dd7,
            'resPQ' => 0x05162463,
            'p_q_inner_data' => 0x83c95aec,
            'req_DH_params' => 0xd712e4be,
            'server_DH_params_ok' => 0xd0e13b5a,
            'server_DH_inner_data' => 0xb5890dba,
            'client_DH_inner_data' => 0x6643b654,
            'set_client_DH_params' => 0xf5045f1f,
            'auth_DH_gen_ok' => 0x3bcbf734,
            'rpc_result' => 0xf35c6d01,
            'rpc_error' => 0x2144ca19,
            'bad_server_salt' => 0xedab447b,
            'gzip_packed' => 0x3072cfa1,
            'invokeWithLayer' => 0xda9b0d0d,
            'help.getNearestDc' => 0x1fb33026,
        ];
        foreach ($goldens as $name => $id) {
            $this->assertSame($id, TLRegistry::id($name), "constructor id mismatch for {$name}");
        }
    }

    public function testUnknownNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TLRegistry::id('no.such_constructor');
    }

    public function testRegisterAddsNewLine(): void
    {
        TLRegistry::register('test_only#1cb5c415 dummy:Vector<long> = TestOnly');
        $this->assertSame(0x1cb5c415, TLRegistry::id('test_only'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Wire/TLRegistryTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

use InvalidArgumentException;

/**
 * Constructor-name ↔ CRC32-id registry built from canonical TL schema lines.
 * IDs are computed at runtime from the canonical string (single spaces, exact field order).
 */
class TLRegistry
{
    public const VECTOR = 0x1cb5c415;

    /** @var array<string, int> name => id */
    protected static array $ids = [];

    /** @var array<string, string> name => canonical line */
    protected static array $signatures = [];

    public const SCHEMA = [
        'req_pq_multi nonce:int128 = ResPQ',
        'resPQ nonce:int128 server_nonce:int128 pq:bytes server_public_key_fingerprints:Vector<long> = ResPQ',
        'p_q_inner_data pq:bytes p:bytes q:bytes nonce:int128 server_nonce:int128 new_nonce:int128 = P_Q_inner_data',
        'req_DH_params nonce:int128 server_nonce:int128 p:bytes q:bytes public_key_fingerprint:long encrypted_data:bytes = Server_DH_Params',
        'server_DH_params_ok nonce:int128 server_nonce:int128 encrypted_data:bytes = Server_DH_Params',
        'server_DH_inner_data nonce:int128 server_nonce:int128 g:int dh_prime:bytes g_a:bytes server_time:int = Server_DH_inner_data',
        'client_DH_inner_data nonce:int128 server_nonce:int128 retry_id:long g_b:bytes = Client_DH_Inner_Data',
        'set_client_DH_params nonce:int128 server_nonce:int128 encrypted_data:bytes = Set_client_DH_params_response',
        'auth_DH_gen_ok nonce:int128 server_nonce:int128 new_nonce_hash1:int128 = Set_client_DH_params_response',
        'invokeWithLayer layer:int query:!X = X',
        'initConnection flags:# api_id:int device_model:string system_version:string app_version:string system_lang_code:string lang_pack:string lang_code:string proxy:flags.0?InputClientProxy params:flags.1?JSONValue query:!X = X',
        'help.getNearestDc = NearestDc',
        'help.getConfig = Config',
        'auth.importBotAuthorization flags:int api_id:int api_hash:string bot_auth_token:string = auth.Authorization',
        'rpc_result req_msg_id:long result:bytes = RpcResult',
        'rpc_error error_code:int error_message:string = RpcResult',
        'bad_server_salt bad_msg_id:long bad_msg_seqno:int error_code:int new_server_salt:long = BadMsgNotification',
        'gzip_packed packed_data:bytes = Object',
    ];

    protected static function boot(): void
    {
        if (static::$ids !== []) {
            return;
        }
        foreach (self::SCHEMA as $line) {
            self::register($line);
        }
    }

    public static function register(string $canonicalLine): void
    {
        if (!preg_match('/^([A-Za-z0-9_.]+)#([0-9a-fA-F]{1,8})\b/', $canonicalLine, $m)) {
            // Line without explicit id: compute from the full canonical string.
            $name = trim(explode(' ', $canonicalLine)[0]);
            $id = self::crc32Canonical($canonicalLine);
        } else {
            $name = $m[1];
            $id = (int)hexdec(str_pad($m[2], 8, '0', STR_PAD_LEFT));
        }
        static::$ids[$name] = $id;
        static::$signatures[$name] = $canonicalLine;
    }

    public static function id(string $constructorName): int
    {
        self::boot();
        if (!isset(static::$ids[$constructorName])) {
            throw new InvalidArgumentException("TLRegistry: unknown constructor '{$constructorName}'");
        }
        return static::$ids[$constructorName];
    }

    public static function signature(string $constructorName): string
    {
        self::boot();
        if (!isset(static::$signatures[$constructorName])) {
            throw new InvalidArgumentException("TLRegistry: unknown constructor '{$constructorName}'");
        }
        return static::$signatures[$constructorName];
    }

    /**
     * crc32 of the canonical string, interpreted as little-endian 32-bit.
     * Reference canonicalization is "single spaces, nothing trimmed";
     * callers must pass already-canonical lines.
     */
    public static function crc32Canonical(string $line): int
    {
        return (int)sprintf('%u', crc32($line));
    }
}
```

Run the test. If a golden mismatches, fix that line's spacing/order against core.telegram.org/mtproto/auth_key and MTProto API docs until the golden passes — do not change the expected hex.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Wire/TLRegistryTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/TL/TLRegistry.php tests/Wire/TLRegistryTest.php
git commit -m "feat(tl): add TLRegistry with canonical schema and golden id vectors"
```

---

### Task 4: TLEncoder / TLDecoder — generic object (de)serialization

**Files:**
- Create: `src/MTProto/TL/TLEncoder.php`, `src/MTProto/TL/TLDecoder.php`
- Test: `tests/Wire/TLCodecTest.php`

**Interfaces:**
- Consumes: `TLRegistry::id/signature/VECTOR`; `TLSerializer::packString/unpackString/packInt/...` (existing).
- Produces:
  - `TLEncoder::encodeObject(string $constructor, array $args): string` — serializes constructor id + fields per registry signature. Field types supported: `int` → 4B LE, `long` → 8B LE, `#` (flags) → 4B LE (caller passes `'flags' => 0` explicitly), `int128`/`int256` → raw 16/32B string arg, `bytes`/`string` → TL-prefixed, `Vector<T>` → vector header, `X`/`Object`/`!X` → recursion into a nested array with `_` key, `flags.N?T` → skip if key absent.
  - `TLDecoder::decodeObject(string $data, ?int &$offset, array $contextTypes = []): array` — generic decoder returning `['_' => inferredName, fields...]`; `$offset` advances. Type inference via constructor-id reverse lookup (`TLRegistry::findByNotUsed`— see implementation: registry gains `idToName()` map via `register`). Unknown ids decode as `['_unknown' => hex id]` and consume nothing (caller must handle); for the wire path we only decode registered constructors.
  - Registry addition: `TLRegistry::nameOf(int $id): ?string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use PHPUnit\Framework\TestCase;

class TLCodecTest extends TestCase
{
    public function testResPQRoundTrip(): void
    {
        $nonce = random_bytes(16);
        $serverNonce = random_bytes(16);
        $args = [
            'nonce' => $nonce,
            'server_nonce' => $serverNonce,
            'pq' => "\x01\x02",
            'server_public_key_fingerprints' => [0x0102030405060708],
        ];
        $bin = TLEncoder::encodeObject('resPQ', $args);
        $this->assertSame(pack('V', 0x05162463), substr($bin, 0, 4));

        $offset = 0;
        $decoded = TLDecoder::decodeObject($bin, $offset);
        $this->assertSame('resPQ', $decoded['_']);
        $this->assertSame($nonce, $decoded['nonce']);
        $this->assertSame($serverNonce, $decoded['server_nonce']);
        $this->assertSame("\x01\x02", $decoded['pq']);
        $this->assertSame([0x0102030405060708], $decoded['server_public_key_fingerprints']);
        $this->assertSame(strlen($bin), $offset);
    }

    public function testFlagsSkippedWhenAbsent(): void
    {
        $bin = TLEncoder::encodeObject('initConnection', [
            'flags' => 0,
            'api_id' => 12345,
            'device_model' => 'test',
            'system_version' => 'test',
            'app_version' => '1.0',
            'system_lang_code' => 'en',
            'lang_pack' => '',
            'lang_code' => 'en',
            'query' => ['_' => 'help.getNearestDc'],
        ]);
        $offset = 0;
        $decoded = TLDecoder::decodeObject($bin, $offset);
        $this->assertSame('initConnection', $decoded['_']);
        $this->assertSame(12345, $decoded['api_id']);
        $this->assertSame('help.getNearestDc', $decoded['query']['_']);
    }

    public function testNameOfReverseLookup(): void
    {
        $this->assertSame('req_pq_multi', TLRegistry::nameOf(0x778e4dd7));
        $this->assertNull(TLRegistry::nameOf(0xdeadbeef));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Wire/TLCodecTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write minimal implementation**

Add to `TLRegistry`:

```php
    /** @var array<int, string> id => name */
    protected static array $names = [];

    // in register(): after computing $name/$id:
    // static::$names[$id] = $name;

    public static function nameOf(int $id): ?string
    {
        self::boot();
        return static::$names[$id] ?? null;
    }
```

`TLEncoder`:

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

use RuntimeException;

class TLEncoder
{
    public static function encodeObject(string $constructor, array $args): string
    {
        $bin = TLSerializer::packInt(TLRegistry::id($constructor));
        $signature = TLRegistry::signature($constructor);
        $fields = self::fieldsOf($signature);
        foreach ($fields as [$fieldName, $fieldType]) {
            if ($fieldType === 'flags' || $fieldType === '#') {
                $bin .= TLSerializer::packInt((int)($args[$fieldName] ?? 0));
                continue;
            }
            if (!array_key_exists($fieldName, $args)) {
                throw new RuntimeException("TLEncoder: missing field '{$fieldName}' for {$constructor}");
            }
            $bin .= self::encodeValue($fieldType, $args[$fieldName]);
        }
        return $bin;
    }

    public static function encodeValue(string $type, mixed $value): string
    {
        return match (true) {
            $type === 'int' => TLSerializer::packInt((int)$value),
            $type === 'long' => TLSerializer::packLong((int)$value),
            $type === 'int128' || $type === 'int256' => str_pad((string)$value, $type === 'int128' ? 16 : 32, "\x00", STR_PAD_LEFT),
            $type === 'bytes' || $type === 'string' => TLSerializer::packString((string)$value),
            str_starts_with($type, 'Vector<') => TLSerializer::packVector(
                $value,
                fn($item) => self::encodeValue(substr($type, 7, -1), $item)
            ),
            str_starts_with($type, 'flags.') => '', // presence handled by skip; encodeValue never called for absent keys
            default => is_array($value)
                ? self::encodeObject((string)($value['_'] ?? throw new RuntimeException('TLEncoder: nested object missing "_" key')), $value)
                : throw new RuntimeException("TLEncoder: cannot encode {$type} from scalar"),
        };
    }

    /**
     * @return list<array{0: string, 1: string}> [name, type] pairs in schema order
     */
    public static function fieldsOf(string $signature): array
    {
        // "name#id f1:t1 f2:t2 = Type" (id optional)
        $body = preg_replace('/^[A-Za-z0-9_.]+(#[0-9a-fA-F]+)?\s*/', '', $signature);
        $body = trim(explode('=', (string)$body)[0]);
        $fields = [];
        if ($body === '' || $body === null) {
            return $fields;
        }
        foreach (explode(' ', $body) as $token) {
            [$name, $type] = explode(':', $token, 2);
            $fields[] = [$name, $type];
        }
        return $fields;
    }
}
```

`TLDecoder` (mirror): read constructor id → `TLRegistry::nameOf` → walk `TLEncoder::fieldsOf` and decode each type (skipping `flags.N?T` whose bit is clear; `X`/`Object`/`!X` recurse). Store ints as int, longs as int, bytes/string as string, vectors as arrays. `unknown id` → `['_unknown' => sprintf('0x%08x', $id)]` and throw — decoder only supports registered ids.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Wire/TLCodecTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/TL/TLEncoder.php src/MTProto/TL/TLDecoder.php src/MTProto/TL/TLRegistry.php tests/Wire/TLCodecTest.php
git commit -m "feat(tl): generic schema-driven TLEncoder/TLDecoder"
```

---

### Task 5: PqFactorizer — Pollard rho in its canonical home

**Files:**
- Create: `src/MTProto/Crypto/PqFactorizer.php`
- Test: `tests/Wire/PqFactorizerTest.php`

**Interfaces:**
- Consumes: `phpseclib3\Math\BigInteger`.
- Produces: `PqFactorizer::factorize(string $pqBytes): array{0: string, 1: string}` — returns `[smaller, larger]` as big-endian byte strings.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Crypto\PqFactorizer;
use PHPUnit\Framework\TestCase;

class PqFactorizerTest extends TestCase
{
    /**
     * Known-good vectors from the official MTProto docs
     * (core.telegram.org/mtproto/samples-authkey): pq=17ED48941FD77AEE...
     */
    public function testOfficialVector(): void
    {
        $pqHex = '17ED48941FD77AEE45CA50295866E0EE6B4AE2BE9859F2EA4F70566D6ADCD9CF';
        [$p, $q] = PqFactorizer::factorize(hex2bin($pqHex));
        // docs: P = 494C553B, Q = 53911073
        $this->assertSame('494C553B', strtoupper(bin2hex($p)));
        $this->assertSame('53911073', strtoupper(bin2hex($q)));
    }

    public function testSmallSemiprime(): void
    {
        [$p, $q] = PqFactorizer::factorize(hex2bin('0x0D')); // 13*... no: use 15 = 3*5 via bytes
        // simpler: 15 as single byte
        [$p2, $q2] = PqFactorizer::factorize("\x0f");
        $this->assertSame(3, (int)bin2hex($p2));
        $this->assertSame(5, (int)bin2hex($q2));
    }
}
```

Note: the second test's `$p/$q` from the first call are unused — delete the stray first two lines and keep only the `"\x0f"` case (plan-author cleanup note; final test body):

```php
    public function testSmallSemiprime(): void
    {
        [$p2, $q2] = PqFactorizer::factorize("\x0f");
        $this->assertSame(3, (int)hexdec(bin2hex($p2)));
        $this->assertSame(5, (int)hexdec(bin2hex($q2)));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Wire/PqFactorizerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Crypto;

use phpseclib3\Math\BigInteger;
use RuntimeException;

/**
 * Pollard's rho factorization for the semiprime pq used by the MTProto
 * key exchange (p, q are ~32-bit; pq up to 64 bits).
 */
class PqFactorizer
{
    /** @return array{0: string, 1: string} [smaller, larger] big-endian bytes */
    public static function factorize(string $pqBytes): array
    {
        $pq = new BigInteger(bin2hex($pqBytes), 16);
        $one = new BigInteger(1);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $x = new BigInteger(random_int(1, PHP_INT_MAX));
            $y = clone $x;
            $c = new BigInteger(random_int(1, PHP_INT_MAX));
            $g = $one;

            while ($g->equals($one)) {
                $x = self::f($x, $c, $pq);
                $y = self::f(self::f($y, $c, $pq), $c, $pq);
                $g = $x->subtract($y)->abs()->gcd($pq); // BigInteger::gcd exists? if not: use extended GCD via modPow? see note
            }

            if (!$g->equals($pq)) {
                $p = $g;
                $q = $pq->divide($g)[0];
                $smaller = $p->compare($q) > 0 ? $q : $p;
                $larger = $p->compare($q) > 0 ? $p : $q;
                return [
                    self::toBytes($smaller),
                    self::toBytes($larger),
                ];
            }
        }
        throw new RuntimeException('PqFactorizer: failed to factor pq after 8 attempts');
    }

    protected static function f(BigInteger $x, BigInteger $c, BigInteger $n): BigInteger
    {
        return $x->multiply($x)->add($c)->divide($n)[1]; // (x^2 + c) mod n
    }

    protected static function toBytes(BigInteger $n): string
    {
        $hex = $n->toHex();
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        return hex2bin($hex);
    }
}
```

Note: verify `BigInteger::gcd()` exists in the installed phpseclib3 (`vendor/phpseclib`) — the existing `DiffieHellman::factorizePq` already calls a gcd-like helper; reuse its exact call pattern if the signature differs. The existing `DiffieHellman` implementation must NOT be modified or removed — `PqFactorizer` is the new canonical home; a later refactor task wires it in.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Wire/PqFactorizerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/Crypto/PqFactorizer.php tests/Wire/PqFactorizerTest.php
git commit -m "feat(crypto): add PqFactorizer with official doc vector test"
```

---

### Task 6: Telegram public key + AuthKeyFactory handshake

**Files:**
- Create: `src/MTProto/resources/telegram_public_key.pub` (downloaded), `src/MTProto/Crypto/AuthKeyFactory.php`
- Test: `tests/Wire/AuthKeyFactoryOfflineTest.php`

**Interfaces:**
- Consumes: `PqFactorizer::factorize` (Task 5), `TLEncoder/TLDecoder` (Task 4), `PlainConnection` (Task 7 — factory takes a `PlainConnection` injected; test uses a fake), `PacketCodec`-style AES-IGE via existing `AesIge`, phpseclib RSA.
- Produces: `AuthKeyFactory::generate(PlainConnection $conn, int $dcId): SessionData` (SessionData with `authKey` 256B, `dcId`, `serverTimeDelta`); `AuthKeyFactory::serverSalt(string $newNonce, string $serverNonce): string` (8B); `AuthKeyFactory::fingerprintOf(string $pemKey): int`.

- [ ] **Step 0: Fetch the public key**

```bash
curl -fsSL https://core.telegram.org/mtproto_rsa_public_key -o src/MTProto/resources/telegram_public_key.pub
```
Manually inspect the file: it must be PEM blocks. Normalize to a single PEM body. If the fetch fails (offline), grab the same content from https://core.telegram.org/mtproto and save; the fingerprint test below then self-verifies it at runtime against the server's list.

- [ ] **Step 1: Write the failing test (offline parts)**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Crypto\AuthKeyFactory;
use PHPUnit\Framework\TestCase;

class AuthKeyFactoryOfflineTest extends TestCase
{
    public function testServerSaltIsXorOfNonceAndServerNonce(): void
    {
        $newNonce = str_repeat("\x11", 32);
        $serverNonce = str_repeat("\x22", 16);
        $salt = AuthKeyFactory::serverSalt($newNonce, $serverNonce);
        $this->assertSame(8, strlen($salt));
        // first 8 bytes of new_nonce XOR first 8 bytes of server_nonce
        $this->assertSame(str_repeat("\x33", 8), $salt);
    }

    public function testNewNonceHash1MatchesSha1Recipe(): void
    {
        $newNonce = random_bytes(32);
        $authKey = random_bytes(256);
        $hash1 = AuthKeyFactory::newNonceHash1($newNonce, $authKey);
        $this->assertSame(16, strlen($hash1));
        $this->assertSame(substr(sha1($newNonce . "\x01" . $authKey, true), 0, 16), $hash1);
    }

    public function testFingerprintOfWellKnownKeyIsStable(): void
    {
        $pem = file_get_contents(__DIR__ . '/../../src/MTProto/resources/telegram_public_key.pub');
        $this->assertNotFalse($pem);
        $fp = AuthKeyFactory::fingerprintOf($pem);
        $this->assertGreaterThan(0, $fp);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Wire/AuthKeyFactoryOfflineTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Crypto;

use MeRezaRezaei\Teleproto\MTProto\Connection\PlainConnection;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Math\BigInteger;
use RuntimeException;

/**
 * Full MTProto 2.0 authorization-key handshake:
 * req_pq_multi -> req_DH_params -> set_client_DH_params.
 */
class AuthKeyFactory
{
    public static function serverSalt(string $newNonce, string $serverNonce): string
    {
        return substr($newNonce, 0, 8) ^ substr($serverNonce, 0, 8);
    }

    public static function newNonceHash1(string $newNonce, string $authKey): string
    {
        return substr(sha1($newNonce . "\x01" . $authKey, true), 0, 16);
    }

    /**
     * lower 63 bits of SHA1 of the DER-encoded RSAPublicKey (without header/NULL).
     */
    public static function fingerprintOf(string $pemOrDer): int
    {
        $key = PublicKeyLoader::load($pemOrDer);
        // phpseclib exposes the raw DER via export('DER'); strip the SubjectPublicKeyInfo
        // header down to the RSAPublicKey sequence, then SHA1 -> lower 63 bits.
        $der = $key->toString('DER');
        $offset = strpos($der, "\x30\x82");
        if ($offset === false) {
            throw new RuntimeException('AuthKeyFactory: unexpected RSA DER');
        }
        $rsaBody = substr($der, $offset); // RSAPublicKey sequence starts here
        $hash = sha1($rsaBody, true);
        $low63 = substr($hash, -8);
        $low63[0] = $low63[0] & "\x7f";
        return (int)(new BigInteger(bin2hex($low63), 16)->toString());
    }

    public static function generate(PlainConnection $conn, int $dcId): SessionData
    {
        $nonce = random_bytes(16);
        $newNonce = random_bytes(32);

        // --- Step 1: req_pq_multi
        $resPq = $conn->request(TLEncoder::encodeObject('req_pq_multi', ['nonce' => $nonce]));
        $offset = 0;
        $resPqObj = TLDecoder::decodeObject($resPq, $offset);
        if ($resPqObj['_'] !== 'resPQ' || $resPqObj['nonce'] !== $nonce) {
            throw new RuntimeException('AuthKeyFactory: bad resPQ');
        }
        $serverNonce = $resPqObj['server_nonce'];
        [$p, $q] = PqFactorizer::factorize($resPqObj['pq']);

        // --- RSA payload: p_q_inner_data padded to 192 bytes, PKCS1 v1.5
        $pem = file_get_contents(__DIR__ . '/../resources/telegram_public_key.pub');
        $fingerprint = self::fingerprintOf($pem);
        if (!in_array($fingerprint, $resPqObj['server_public_key_fingerprints'], false)) {
            throw new RuntimeException('AuthKeyFactory: server fingerprints do not include the bundled public key');
        }

        $inner = TLEncoder::encodeObject('p_q_inner_data', [
            'pq' => $resPqObj['pq'], 'p' => $p, 'q' => $q,
            'nonce' => $nonce, 'server_nonce' => $serverNonce, 'new_nonce' => $newNonce,
        ]);
        $inner = str_pad($inner, 192, random_bytes(1)); // pad to exactly 192 with random bytes

        $rsa = PublicKeyLoader::load($pem)->withPadding(RSA::ENCRYPTION_PKCS1 | RSA::SIGNATURE_PKCS1);
        $encryptedInner = $rsa->encrypt($inner);

        // --- Step 2: req_DH_params
        $reqDh = TLEncoder::encodeObject('req_DH_params', [
            'nonce' => $nonce, 'server_nonce' => $serverNonce,
            'p' => $p, 'q' => $q,
            'public_key_fingerprint' => $fingerprint,
            'encrypted_data' => $encryptedInner,
        ]);
        $serverDh = $conn->request($reqDh);
        $offset = 0;
        $serverDhObj = TLDecoder::decodeObject($serverDh, $offset);
        if ($serverDhObj['_'] !== 'server_DH_params_ok') {
            throw new RuntimeException('AuthKeyFactory: DH params rejected (' . $serverDhObj['_'] . ')');
        }

        $aesKey = hash('sha256', $newNonce . $serverNonce, true);
        $aesIv = substr(hash('sha256', $serverNonce . $newNonce, true), 0, 16)
               . substr(hash('sha256', $newNonce . $newNonce, true), 0, 16);
        $plainDh = AesIge::decrypt($serverDhObj['encrypted_data'], $aesKey, $aesIv);

        $offset = 0;
        $innerDh = TLDecoder::decodeObject($plainDh, $offset);
        if ($innerDh['_'] !== 'server_DH_inner_data') {
            throw new RuntimeException('AuthKeyFactory: bad server_DH_inner_data');
        }

        // --- Step 3: set_client_DH_params
        $g = new BigInteger((string)$innerDh['g']);
        $dhPrime = new BigInteger(bin2hex($innerDh['dh_prime']), 16);
        $gA = new BigInteger(bin2hex($innerDh['g_a']), 16);
        $b = new BigInteger(random_bytes(256), 256);
        $gB = $g->modPow($b, $dhPrime);

        $clientInner = TLEncoder::encodeObject('client_DH_inner_data', [
            'nonce' => $nonce, 'server_nonce' => $serverNonce,
            'retry_id' => 0,
            'g_b' => self::bigToBytes($gB),
        ]);
        $padLen = (16 - (strlen($clientInner) % 16)) % 16;
        $clientInner .= $padLen > 0 ? random_bytes($padLen) : '';
        $clientEncrypted = AesIge::encrypt($clientInner, $aesKey, $aesIv);

        $setDh = TLEncoder::encodeObject('set_client_DH_params', [
            'nonce' => $nonce, 'server_nonce' => $serverNonce, 'encrypted_data' => $clientEncrypted,
        ]);
        $authRes = $conn->request($setDh);
        $offset = 0;
        $authObj = TLDecoder::decodeObject($authRes, $offset);
        if ($authObj['_'] !== 'auth_DH_gen_ok') {
            throw new RuntimeException('AuthKeyFactory: DH generation failed (' . $authObj['_'] . ')');
        }
        if (!hash_equals(self::newNonceHash1($newNonce, /* placeholder */ ''), $authObj['new_nonce_hash1'])) {
            // placeholder replaced below — authKey computed first, then verified.
        }

        $authKey = self::bigToBytes($gA->modPow($b, $dhPrime));
        $authKey = str_pad($authKey, 256, "\x00", STR_PAD_LEFT);

        if (!hash_equals(self::newNonceHash1($newNonce, $authKey), $authObj['new_nonce_hash1'])) {
            throw new RuntimeException('AuthKeyFactory: new_nonce_hash1 mismatch');
        }

        return new SessionData(
            dcId: $dcId,
            authKey: $authKey,
            serverTimeDelta: (int)$innerDh['server_time'] - time(),
        );
    }

    protected static function bigToBytes(BigInteger $n): string
    {
        $hex = $n->toHex();
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        return hex2bin($hex);
    }
}
```

Clean up before committing: remove the placeholder `if (!hash_equals(...))` block that references an empty string — the real verification is the second `hash_equals` after `$authKey` is computed. Also verify `SessionData`'s constructor parameter names (`dcId`, `authKey`, `serverTimeDelta`, `userId`) against `src/MTProto/SessionData.php` and adapt.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Wire/AuthKeyFactoryOfflineTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/Crypto/AuthKeyFactory.php src/MTProto/resources/telegram_public_key.pub tests/Wire/AuthKeyFactoryOfflineTest.php
git commit -m "feat(crypto): add AuthKeyFactory DH handshake with self-verifying key fingerprint"
```

---

### Task 7: PlainConnection — framed unencrypted requests

**Files:**
- Create: `src/MTProto/Connection/PlainConnection.php`
- Test: `tests/Wire/PlainConnectionTest.php`

**Interfaces:**
- Consumes: `FrameCodec` (Task 2), `StreamSocket` (Task 1).
- Produces: `PlainConnection::connect(string $host, int $port = 443, float $timeout = 10.0): static` (opens socket, writes `0xef` init); `PlainConnection::request(string $payload): string` (send framed, read one frame back); `PlainConnection::close(): void`; `PlainConnection::$socket`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Connection\PlainConnection;
use PHPUnit\Framework\TestCase;

class PlainConnectionTest extends TestCase
{
    public function testRequestEchoesSingleFrameOverLoopback(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        // fake telegram: read 0xee init byte, then frame-echo one message
        $fake = function () use ($server) {
            $client = stream_socket_accept($server, 5.0);
            fread($client, 1); // init byte
            $len = unpack('V', fread($client, 4))[1];
            $payload = fread($client, $len);
            fwrite($client, pack('V', strlen($payload)) . $payload);
            fclose($client);
        };

        $conn = PlainConnection::connect($host, (int)$port, timeout: 5.0);
        // fake server serves exactly one request
        $fake(); // blocking echo within 5s window
        $response = $conn->request('handshake-payload');
        $this->assertSame('handshake-payload', $response);
        $conn->close();
        fclose($server);
    }
}
```

(Note: run the fake server before `request` only if the test deadlocks — prefer launching `$fake` in a child process or via `proc_open` if single-threaded blocking is an issue; simplest robust variant is using `stream_socket_pair`-based in-memory transport injected as `$socket`. If the loopback variant proves flaky, refactor `PlainConnection` to accept an already-open `$socket` via constructor and test against `stream_socket_pair` — keep the public `connect()` API regardless.)

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Wire/PlainConnectionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Connection;

use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;

/**
 * Unencrypted framed connection used only for the auth-key handshake.
 */
class PlainConnection
{
    /** @var resource */
    public $socket;

    /** @param resource $socket */
    public function __construct($socket)
    {
        $this->socket = $socket;
    }

    public static function connect(string $host, int $port = 443, float $timeout = 10.0): static
    {
        $socket = StreamSocket::createConnection($host, $port, timeout: $timeout);
        FrameCodec::writeInit($socket);
        return new static($socket);
    }

    public function request(string $payload): string
    {
        FrameCodec::sendMessage($this->socket, $payload);
        return FrameCodec::receiveMessage($this->socket);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Wire/PlainConnectionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/Connection/PlainConnection.php tests/Wire/PlainConnectionTest.php
git commit -m "feat(connection): add PlainConnection for handshake transport"
```

---

### Task 8: EncryptedConnection — real RPC envelopes

**Files:**
- Create: `src/MTProto/Connection/EncryptedConnection.php`
- Test: `tests/Wire/EncryptedConnectionTest.php`

**Interfaces:**
- Consumes: `FrameCodec`, `PacketCodec::encryptPacket/decryptPacket` (existing), `TLRegistry`, `TLDecoder`, `SessionData`.
- Produces: `EncryptedConnection::connect(SessionData $session, string $host, int $port = 443, float $timeout = 10.0): static`; `EncryptedConnection::call(string $constructor, array $args = []): array` — wraps query in `invokeWithLayer(layer=227, initConnection(..., query))` on first call only, sets msg_id (`(int)(microtime(true)*2**32) | 1`), seq 0, salt 0 initially; handles `bad_server_salt` (store salt, resend once) and `gzip_packed` (inflate via `gzdecode`), `rpc_error` → throw `TelegramException($error_message, $error_code)`; returns decoded result array. `EncryptedConnection::close(): void`. Exposes `->lastSessionData(): SessionData` (with updated salt delta fields if any).

- [ ] **Step 1: Write the failing test (offline envelope math, no socket)**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Connection\EncryptedConnection;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use PHPUnit\Framework\TestCase;

class EncryptedConnectionTest extends TestCase
{
    public function testFirstQueryIsWrappedInInvokeWithLayerAndInitConnection(): void
    {
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $body = EncryptedConnection::buildFirstQueryBody($session->authKey === '' ? 227 : 227, [
            'api_id' => 12345,
            'device_model' => 'Teleproto',
            'system_version' => PHP_VERSION,
            'app_version' => '1.0.0',
            'system_lang_code' => 'en',
            'lang_pack' => '',
            'lang_code' => 'en',
            'query' => ['_' => 'help.getNearestDc'],
        ]);
        $this->assertSame(pack('V', 0xda9b0d0d), substr($body, 0, 4)); // invokeWithLayer
        $layer = unpack('V', substr($body, 4, 4))[1];
        $this->assertSame(227, $layer);
    }

    public function testDecodeGzipPackedResult(): void
    {
        $inner = hex2bin('0fbdb26f') . 'demo'; // fake id + junk; just prove inflation happens
        $packed = gzencode($inner, 9);
        $result = EncryptedConnection::unwrapResultIfGzipped(
            pack('V', 0x3072cfa1) . \MeRezaRezaei\Teleproto\MTProto\TL\TLSerializer::packString($packed)
        );
        $this->assertSame($inner, $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Wire/EncryptedConnectionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Connection;

use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\MTProto\Crypto\PacketCodec;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use MeRezaRezaei\Teleproto\MTProto\TL\TLSerializer;
use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use RuntimeException;

/**
 * Encrypted MTProto 2.0 RPC connection (single in-flight query; sufficient for CLI/short jobs).
 */
class EncryptedConnection
{
    public const LAYER = 227;

    /** @var resource|null */
    protected $socket;
    protected int $serverSalt = 0;
    protected int $sessionId;
    protected bool $inited = false;

    public function __construct(protected SessionData $session, $socket = null)
    {
        $this->socket = $socket;
        $this->sessionId = (int)unpack('P', random_bytes(8))[1];
    }

    public static function connect(SessionData $session, string $host, int $port = 443, float $timeout = 10.0): static
    {
        $socket = \MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket::createConnection($host, $port, timeout: $timeout);
        FrameCodec::writeInit($socket);
        return new static($session, $socket);
    }

    public static function buildFirstQueryBody(int $layer, array $initConnectionArgs): string
    {
        $init = TLEncoder::encodeObject('initConnection', array_merge(['flags' => 0], $initConnectionArgs));
        return TLSerializer::packInt(TLRegistry::id('invokeWithLayer'))
            . TLSerializer::packInt($layer)
            . $init;
    }

    public static function unwrapResultIfGzipped(string $bin): string
    {
        $id = unpack('V', substr($bin, 0, 4))[1];
        if ($id === TLRegistry::id('gzip_packed')) {
            $offset = 4;
            $packed = TLSerializer::unpackString($bin, $offset);
            $inflated = gzdecode($packed);
            if ($inflated === false) {
                throw new RuntimeException('EncryptedConnection: gzdecode failed');
            }
            return $inflated;
        }
        return $bin;
    }

    public function call(string $constructor, array $args = []): array
    {
        if ($this->socket === null) {
            throw new RuntimeException('EncryptedConnection: not connected');
        }

        $body = $this->inited
            ? TLEncoder::encodeObject($constructor, $args)
            : self::buildFirstQueryBody(self::LAYER, [
                'api_id' => (int)env('TELEPROTO_API_ID_INTERNAL', 0) ?: $this->apiIdFromSession(),
                'device_model' => 'Teleproto',
                'system_version' => PHP_OS . ' PHP ' . PHP_VERSION,
                'app_version' => '1.0.0',
                'system_lang_code' => 'en',
                'lang_pack' => '',
                'lang_code' => 'en',
                'query' => array_merge(['_' => $constructor], $args),
            ]);

        $maxAttempts = 2;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $this->inited = true;
            $packet = PacketCodec::encryptPacket(
                payload: $body,
                authKey: $this->session->authKey,
                sessionId: $this->sessionId,
                serverSalt: $this->serverSalt,
                seqNo: 0,
                serverTimeDelta: $this->session->serverTimeDelta,
            );
            FrameCodec::sendMessage($this->socket, $packet);
            $responsePacket = FrameCodec::receiveMessage($this->socket);
            $msg = PacketCodec::decryptPacket($responsePacket, $this->session->authKey);

            $offset = 0;
            $result = TLDecoder::decodeObject($msg['payload'], $offset);
            $name = $result['_'] ?? '';

            if ($name === 'rpc_result') {
                $innerBin = self::unwrapResultIfGzipped($result['result']);
                $innerOff = 0;
                $inner = TLDecoder::decodeObject($innerBin, $innerOff);
                if (($inner['_'] ?? '') === 'rpc_error') {
                    throw new TelegramException(
                        'MTProto: ' . $inner['error_message'],
                        (int)$inner['error_code']
                    );
                }
                return $inner;
            }

            if ($name === 'bad_server_salt') {
                $this->serverSalt = (int)$result['new_server_salt'];
                continue; // resend with the fresh salt
            }

            throw new RuntimeException("EncryptedConnection: unexpected response '{$name}'");
        }
        throw new RuntimeException('EncryptedConnection: exhausted retries after bad_server_salt');
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    protected function apiIdFromSession(): int
    {
        return defined('TELEPROTO_LIVE_API_ID') ? TELEPROTO_LIVE_API_ID : 0;
    }
}
```

Cleanup before commit: the `env()` call is framework-bleed into a low-level class — remove it and the `apiIdFromSession()` hack; instead add `public function __construct(protected SessionData $session, $socket = null, protected int $apiId = 0)` and pass `apiId` from `Client`/`DoctorCommand`. Test only exercises static methods, so it stays green.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Wire/EncryptedConnectionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/MTProto/Connection/EncryptedConnection.php tests/Wire/EncryptedConnectionTest.php
git commit -m "feat(connection): encrypted RPC envelopes with gzip + bad_server_salt handling"
```

---

### Task 9: Client live mode wiring

**Files:**
- Modify: `src/MTProto/Client.php` (the `call()` method, lines ~85-102)
- Test: `tests/Wire/ClientLiveModeTest.php`

**Interfaces:**
- Consumes: `AuthKeyFactory`, `EncryptedConnection`, `SessionData`, existing `Client` constructor `(int $apiId, string $apiHash, ?SessionData $session)`.
- Produces: `Client::call(string $method, array $params = []): array` — identical signature; behavior switches on `bool $this->live` (constructor gains optional `bool $live = false`). Live path: if session has no authKey → run `AuthKeyFactory::generate(PlainConnection::connect(dc host), dcId)` and store key on the session; then `EncryptedConnection::connect(...)->call($method, $params)`. Add `Client::live(): static` fluent enable (used by doctor) and keep the stub path otherwise.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use PHPUnit\Framework\TestCase;

class ClientLiveModeTest extends TestCase
{
    public function testOfflineStubUnchangedByDefault(): void
    {
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $client = new Client(apiId: 1, apiHash: 'h', session: $session);
        $res = $client->call('help.getNearestDc');
        $this->assertSame('rpc_result', $res['_']);
        $this->assertSame('help.getNearestDc', $res['method']);
    }

    public function testLiveRequiresAuthKeyOrFailsFast(): void
    {
        $session = new SessionData(dcId: 2, authKey: ''); // empty key forces handshake attempt
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session))->live();

        // Connecting to a dead port must fail fast with our RuntimeException,
        // proving the live path really attempts the network (not the stub).
        $this->expectException(\RuntimeException::class);
        $client->callToHost('127.0.0.1', 1); // port 1: connection refused
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Wire/ClientLiveModeTest.php`
Expected: FAIL — `live()` / `callToHost()` undefined.

- [ ] **Step 3: Write minimal implementation**

In `Client`:

```php
    protected bool $live = false;

    public function live(): static
    {
        $this->live = true;
        return $this;
    }

    public function call(string $method, array $params = []): array
    {
        if (!$this->live) {
            return [ // existing stub, unchanged
                '_' => 'rpc_result', 'method' => $method, 'params' => $params,
                'dc_id' => $this->session?->dcId,
            ];
        }
        return $this->callToHost(...$this->resolveHost());
    }

    public function callToHost(string $host, int $port = 443): array
    {
        $session = $this->session ?? throw new \RuntimeException('MTProto live call: no session');

        if (strlen($session->authKey) !== 256) {
            $plain = \MeRezaRezaei\Teleproto\MTProto\Connection\PlainConnection::connect($host, $port);
            try {
                $fresh = \MeRezaRezaei\Teleproto\MTProto\Crypto\AuthKeyFactory::generate($plain, $session->dcId);
                $this->session = $session = $fresh;
            } finally {
                $plain->close();
            }
        }

        $conn = \MeRezaRezaei\Teleproto\MTProto\Connection\EncryptedConnection::connect($session, $host, $port);
        try {
            return $conn->call('help.getNearestDc'); // default probe; Client::call maps method below
        } finally {
            $conn->close();
        }
    }

    /** @return array{0: string, 1: int} */
    protected function resolveHost(): array
    {
        $dcId = $this->session?->dcId ?? 2;
        return [self::DC_IPS[$dcId] ?? self::DC_IPS[2], self::DEFAULT_PORT];
    }
```

Refine before commit: `callToHost` currently probes `help.getNearestDc` regardless of requested method. Restructure: give `Client` `private ?\MeRezaRezaei\Teleproto\MTProto\Connection\EncryptedConnection $conn = null;` and a `protected function ensureConnection(): EncryptedConnection` that lazily handshakes/connects once; `call()` in live mode becomes `return $this->ensureConnection()->call($method, $params);`, and `callToHost(string $host, int $port)` becomes the test-only seam that forces a fresh handshake to a given host (its body: handshake-if-needed + one `help.getNearestDc` probe, as written above). The two tests must still pass with identical expectations.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Wire/ClientLiveModeTest.php`
Expected: PASS

- [ ] **Step 5: Run the full suite (offline safety)**

Run: `vendor/bin/phpunit`
Expected: PASS — all pre-existing tests plus new Wire tests, zero network use.

- [ ] **Step 6: Commit**

```bash
git add src/MTProto/Client.php tests/Wire/ClientLiveModeTest.php
git commit -m "feat(mtproto): opt-in live wire path in Client with lazy handshake"
```

---

### Task 10: teleproto:doctor — live verification command

**Files:**
- Create: `src/Console/DoctorCommand.php`
- Modify: `src/TeleprotoServiceProvider.php` (register command), `config/teleproto.php` (add `'live_mode' => env('TELEPROTO_LIVE', false)`)
- Test: `tests/Wire/DoctorCommandTest.php`

**Interfaces:**
- Consumes: `Client::live()`, `Client::callToHost`, `AuthKeyFactory`, `TeleprotoClient::botMtproto` (bot check only).
- Produces: Artisan command `teleproto:doctor {--bot : also verify bot MTProto login}` printing timed steps: TCP connect, handshake, `help.getNearestDc` result (this DC / nearest DC), optional bot auth. Exit 0 on success, 1 on failure.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\Console\DoctorCommand;
use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use PHPUnit\Framework\TestCase;

class DoctorCommandTest extends TestCase
{
    public function testDoctorFailsCleanlyOnDeadHost(): void
    {
        $session = new SessionData(dcId: 2, authKey: '');
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session))->live();

        $cmd = new DoctorCommand();
        $cmd->setLaravel(null);
        $exit = $cmd->probeConnectivity($client, host: '127.0.0.1', port: 1);
        $this->assertSame(1, $exit);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Wire/DoctorCommandTest.php`
Expected: FAIL — class/method not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Console;

use Illuminate\Console\Command;
use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use Throwable;

/**
 * Live MTProto health check: TCP + auth-key handshake + help.getNearestDc
 * (optionally bot MTProto login). Requires no Telegram account.
 */
class DoctorCommand extends Command
{
    protected $signature = 'teleproto:doctor {--bot : Also verify bot MTProto authorization} {--dc=2 : Datacenter id}';

    protected $description = 'Verify Teleproto live MTProto connectivity (no account needed)';

    public function handle(): int
    {
        $dcId = (int)$this->option('dc');
        $apiId = (int)(config('teleproto.api_id') ?: $this->ask('Telegram API id'));
        $apiHash = (string)(config('teleproto.api_hash') ?: $this->ask('Telegram API hash'));

        $session = new SessionData(dcId: $dcId, authKey: '');
        $client = (new Client(apiId: $apiId, apiHash: $apiHash, session: $session))->live();

        $exit = $this->probeConnectivity($client, Client::DC_IPS[$dcId] ?? Client::DC_IPS[2], Client::DEFAULT_PORT);

        if ($exit === 0 && $this->option('bot')) {
            $token = (string)(config('teleproto.bot_token') ?: $this->ask('Bot token'));
            $exit = $this->probeBotAuth($apiId, $apiHash, $token, $dcId);
        }
        return $exit;
    }

    public function probeConnectivity(Client $client, string $host, int $port): int
    {
        $t0 = microtime(true);
        try {
            $client->callToHost($host, $port);
            $ms = (int)((microtime(true) - $t0) * 1000);
            $this->components->info("OK handshake+getNearestDc {$host}:{$port} in {$ms}ms");
            return 0;
        } catch (Throwable $e) {
            $this->components->error("FAIL {$host}:{$port} — " . $e->getMessage());
            return 1;
        }
    }

    protected function probeBotAuth(int $apiId, string $apiHash, string $token, int $dcId): int
    {
        try {
            $auth = app(\MeRezaRezaei\Teleproto\Services\TeleprotoAuthService::class);
            $res = $auth->loginBot($token, $apiId, $apiHash, $dcId);
            $this->components->info('OK bot MTProto authorization (session generated)');
            unset($res);
            return 0;
        } catch (Throwable $e) {
            $this->components->error('Bot MTProto login failed — ' . $e->getMessage());
            return 1;
        }
    }
}
```

Register in `TeleprotoServiceProvider::boot()` commands array alongside LoginCommand/PollCommand, and add `'live_mode' => env('TELEPROTO_LIVE', false),` to `config/teleproto.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Wire/DoctorCommandTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Console/DoctorCommand.php src/TeleprotoServiceProvider.php config/teleproto.php tests/Wire/DoctorCommandTest.php
git commit -m "feat(cli): add teleproto:doctor live MTProto verification command"
```

---

### Task 11: First live run + composer/ext hygiene

**Files:**
- Modify: `composer.json` (add `ext-zlib`)
- No new tests (execution task; doctor output is the verification)

**Interfaces:**
- Consumes: everything above.
- Produces: proof the package speaks real MTProto; `ext-zlib` requirement recorded.

- [ ] **Step 1: Add ext-zlib + full offline suite**

In composer.json `require`: `"ext-zlib": "*",` (alphabetical position after ext-openssl). Then:

```bash
composer validate && vendor/bin/phpunit
```
Expected: valid + all tests PASS offline.

- [ ] **Step 2: Run the doctor against real DC2**

```bash
TELEGRAM_API_ID=<real id> TELEGRAM_API_HASH=<real hash> php artisan teleproto:doctor
```
Expected output contains `OK handshake+getNearestDc 149.154.167.51:443 in <ms>ms`.

If it fails: debug against the handshake order in `AuthKeyFactory` (most common failure modes, in observed frequency: (1) a golden constructor string still mis-typed — registry throws; (2) AES-IV recipe byte order — swap the two hash halves; (3) msg_id parity — ensure `|1` and monotonic; (4) 192-byte pad — must be exactly 192 including data). Fix code, never the doctor.

- [ ] **Step 3: Bot MTProto doctor (optional if token available)**

```bash
TELEGRAM_API_ID=<id> TELEGRAM_API_HASH=<hash> TELEGRAM_BOT_TOKEN=<token> php artisan teleproto:doctor --bot
```
Expected: both OK lines.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock 2>/dev/null || git add composer.json
git commit -m "chore: require ext-zlib for gzipped MTProto responses; verified live handshake"
```

---

### Task 12: Static analysis pass + CHANGELOG

**Files:**
- Modify: `composer.json` (require-dev larastan), `phpstan.neon.dist` (create), `CHANGELOG.md`
- Test: none new — `composer analyse` must pass

**Interfaces:**
- Consumes: all sources.
- Produces: `composer analyse` script (larastan level 5, clean), documented release notes.

- [ ] **Step 1: Add larastan**

```bash
composer require --dev larastan/larastan "^2.0" --with-all-dependencies
```

Create `phpstan.neon.dist`:

```neon
parameters:
    level: 5
    paths:
        - src
    excludePaths:
        - src/MTProto/resources
    ignoreErrors:
        - '#is not covariant#'
```

Add composer script: `"analyse": "vendor/bin/phpstan analyse"`.

- [ ] **Step 2: Run and fix**

```bash
composer analyse
```
Expected: 0 errors. Fix findings (types, docblocks). If a finding reveals a genuine bug (e.g., wrong byte math in AuthKeyFactory), add a regression test before fixing.

- [ ] **Step 3: Update CHANGELOG**

Prepend an "Unreleased" section to `CHANGELOG.md`:

```markdown
## [Unreleased]
### Added
- Real MTProto 2.0 wire path: intermediate TCP framing (`FrameCodec`), auth-key DH handshake (`AuthKeyFactory`), encrypted RPC (`EncryptedConnection`) with gzip_packed + bad_server_salt handling.
- `teleproto:doctor` live verification command (no Telegram account needed).
- `TLRegistry`/`TLEncoder`/`TLDecoder` schema-driven TL engine with golden id vectors.
- `PqFactorizer` (Pollard rho) with official doc vectors; live mode opt-in on `Client` (`->live()`).
### Changed
- `ext-zlib` now required; inert proxy context removed from `StreamSocket` (direct connections only until tunneling ships).
```

- [ ] **Step 4: Full gate + commit**

```bash
composer test && composer analyse && git add -A && git commit -m "chore: larastan level 5 clean + changelog for wire path"
```

---

## Self-Review notes (already applied)

- Spec coverage: audit gaps 1-5 map to Tasks 2, 6(+5), 3, 8(+9), 10-11 respectively. Proxy tunneling explicitly out-of-scope in spec; Task 1 documents it rather than pretending.
- Golden IDs are assertions, not hardcoded engine values — the engine computes from strings; mismatches self-report as test failures with instructions.
- All offline tests avoid network; live behavior is env/flag gated; doctor is the only live entry point in this plan.
