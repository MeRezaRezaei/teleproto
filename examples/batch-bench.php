#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Live msg_container batching benchmark for Teleproto — N requests in ONE round-trip.
 *
 * Usage (from the repository root, after `composer install`):
 *   php examples/batch-bench.php
 *
 * Loads TELEGRAM_API_ID / TELEGRAM_API_HASH / TELEGRAM_USER_SESSION from the
 * repo-root .env (an exported user session from `php artisan teleproto:login`
 * or examples/live-login.php). Without a session string a fresh DH handshake
 * is performed (like examples/live-doctor.php) — but the two authorized calls
 * need a logged-in session, so provide one for a green run.
 *
 * Measures the same 3 requests two ways on one warm connection:
 *   singles — sequential call() round-trip per request (2 rounds; round 1 is
 *             discarded as warmup: connect + invokeWithLayer init + salt)
 *   batched — ONE Client::callMany(): all 3 inside a single msg_container
 *
 * Exit 0 only if every result is sane (nearestDc.this_dc, users[0].id > 0,
 * state.pts — in BOTH modes) AND batched_ms < singles_total_ms * 0.6.
 */

require __DIR__ . '/../vendor/autoload.php';

use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Support\EnvFile;

const ROUNDS = 2; // round 1 = warmup (connect + init), round 2 = measured

$requests = [
    'nearestDc' => ['method' => 'help.getNearestDc', 'params' => []],
    'self' => ['method' => 'users.getUsers', 'params' => ['id' => [['_' => 'inputUserSelf']]]],
    'state' => ['method' => 'updates.getState', 'params' => []],
];

$env = EnvFile::read(__DIR__ . '/../.env');
$apiId = (int) ($env['TELEGRAM_API_ID'] ?? 0);
$apiHash = (string) ($env['TELEGRAM_API_HASH'] ?? '');
$sessionString = trim((string) ($env['TELEGRAM_USER_SESSION'] ?? ''));

if ($apiId === 0 || $apiHash === '') {
    fwrite(STDERR, "TELEGRAM_API_ID / TELEGRAM_API_HASH missing from .env (from https://my.telegram.org).\n");
    exit(1);
}

try {
    $session = $sessionString !== ''
        ? SessionData::importString($sessionString)
        : new SessionData(dcId: 2, authKey: '');
} catch (Throwable $e) {
    fwrite(STDERR, 'Invalid TELEGRAM_USER_SESSION: ' . $e->getMessage() . "\n");
    exit(1);
}

$dcId = $session->dcId;
$host = Client::DC_IPS[$dcId] ?? Client::DC_IPS[2];
echo sprintf(
    "Teleproto batch bench — live DC%d (%s:443), %s\n",
    $dcId,
    $host,
    $sessionString !== '' ? 'user session from .env' : 'fresh handshake (no user session in .env — authorized calls will fail)'
);

$client = (new Client(apiId: $apiId, apiHash: $apiHash, session: $session))->live();

/**
 * One sequential pass over the requests; per-call and total wall time included.
 *
 * @return array{results: array<string, array<string, mixed>>, perCallMs: array<string, float>, totalMs: float}
 */
function runSingles(Client $client, array $requests): array
{
    $results = [];
    $perCallMs = [];
    $tAll = microtime(true);
    foreach ($requests as $key => $request) {
        $t = microtime(true);
        $results[$key] = $client->call($request['method'], $request['params']);
        $perCallMs[$key] = (microtime(true) - $t) * 1000;
    }
    return ['results' => $results, 'perCallMs' => $perCallMs, 'totalMs' => (microtime(true) - $tAll) * 1000];
}

/** @return list<string> empty when sane, else the complaints */
function sanityIssues(array $results): array
{
    $issues = [];
    if (!isset($results['nearestDc']['this_dc'])) {
        $issues[] = 'nearestDc missing this_dc';
    }
    if ((int) ($results['self'][0]['id'] ?? 0) <= 0) {
        $issues[] = 'users[0].id not > 0';
    }
    if (!isset($results['state']['pts'])) {
        $issues[] = 'state missing pts';
    }
    return $issues;
}

try {
    $warmup = runSingles($client, $requests);
    echo sprintf("warmup round (connect + init + 3 calls, discarded): %.1fms\n", $warmup['totalMs']);

    $singles = runSingles($client, $requests);

    $t = microtime(true);
    $batchedResults = $client->callMany($requests);
    $batchedMs = (microtime(true) - $t) * 1000;

    echo sprintf(
        "singles   : %s → total %.1fms\n",
        implode(' | ', array_map(
            fn (string $key, float $ms): string => sprintf('%s %.1fms', $key, $ms),
            array_keys($singles['perCallMs']),
            $singles['perCallMs']
        )),
        $singles['totalMs']
    );
    echo sprintf("batched   : one callMany (3-in-1 container) → %.1fms\n", $batchedMs);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("FAIL — %s: %s\n", get_class($e), $e->getMessage()));
    $client->close();
    exit(1);
}

$singlesIssues = sanityIssues($singles['results']);
$batchedIssues = sanityIssues($batchedResults);
$sane = $singlesIssues === [] && $batchedIssues === [];

echo sprintf(
    "sanity    : nearestDc.this_dc=%s users[0].id=%d state.pts=%s — %s\n",
    var_export($batchedResults['nearestDc']['this_dc'] ?? null, true),
    (int) ($batchedResults['self'][0]['id'] ?? 0),
    var_export($batchedResults['state']['pts'] ?? null, true),
    $sane ? 'OK (both modes)' : 'FAIL'
);
if (!$sane) {
    foreach ([...$singlesIssues, ...$batchedIssues] as $issue) {
        fwrite(STDERR, "insane result: {$issue}\n");
    }
    $client->close();
    exit(1);
}

$thresholdMs = $singles['totalMs'] * 0.6;
$pass = $batchedMs < $thresholdMs;
echo sprintf(
    "verdict   : batched %.1fms vs singles %.1fms (%.1fx) — needs < %.1fms → %s\n",
    $batchedMs,
    $singles['totalMs'],
    $singles['totalMs'] / max($batchedMs, 0.001),
    $thresholdMs,
    $pass ? 'PASS' : 'FAIL'
);
$client->close();
exit($pass ? 0 : 1);
