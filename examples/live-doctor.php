#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Standalone live MTProto verification for Teleproto — no Laravel/artisan needed.
 *
 * Usage (from the repository root, after `composer install`):
 *   TG_API_ID=1821270 TG_API_HASH=... php examples/live-doctor.php [dc]
 *
 *   dc: prod50 (default) | prod51 | test
 *     prod50 = production DC2 149.154.167.50 (the IP shown on my.telegram.org)
 *     prod51 = production DC2 149.154.167.51 (listed in most client DC tables)
 *     test   = TEST DC2 149.154.167.40 (test-configuration key already bundled)
 *
 * Verifies: TCP + intermediate framing + DH auth-key handshake + encrypted
 * help.getNearestDc RPC (Layer 227). Requires no Telegram account.
 */

require __DIR__ . '/../vendor/autoload.php';

use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\SessionData;

$apiId = (int) (getenv('TG_API_ID') ?: 0);
$apiHash = (string) (getenv('TG_API_HASH') ?: '');
if ($apiId === 0 || $apiHash === '') {
    fwrite(STDERR, "Set TG_API_ID and TG_API_HASH env vars (from https://my.telegram.org → API development tools).\n");
    exit(1);
}

$targets = [
    'prod50' => ['production DC2 149.154.167.50', '149.154.167.50'],
    'prod51' => ['production DC2 149.154.167.51', '149.154.167.51'],
    'test' => ['TEST DC2 149.154.167.40', '149.154.167.40'],
];
$which = strtolower((string) ($argv[1] ?? 'prod50'));
if (!isset($targets[$which])) {
    fwrite(STDERR, "Unknown dc '{$which}' — use prod50|prod51|test\n");
    exit(1);
}
[$label, $host] = $targets[$which];

echo "Teleproto live doctor — {$label}:443\n";

$session = new SessionData(dcId: 2, authKey: '');
$client = (new Client(apiId: $apiId, apiHash: $apiHash, session: $session))->live();

$t0 = microtime(true);
try {
    $result = $client->callToHost($host, 443);
} catch (Throwable $e) {
    $ms = (int) ((microtime(true) - $t0) * 1000);
    fwrite(STDERR, sprintf("FAIL after %dms — %s: %s\n", $ms, get_class($e), $e->getMessage()));
    exit(1);
}

$ms = (int) ((microtime(true) - $t0) * 1000);
echo "OK in {$ms}ms — full handshake + encrypted help.getNearestDc:\n";
print_r($result);
