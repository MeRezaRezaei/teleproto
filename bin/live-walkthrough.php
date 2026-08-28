<?php

declare(strict_types=1);

// Live walkthrough of the user surface — never committed output.
require __DIR__ . '/../vendor/autoload.php';

use MeRezaRezaei\Teleproto\Entities\EntityParser;
use MeRezaRezaei\Teleproto\Services\TeleprotoClient;

$env = parse_ini_file(__DIR__ . '/../.env');
$c = new TeleprotoClient((int) $env['TELEGRAM_API_ID'], $env['TELEGRAM_API_HASH']);
$u = $c->fromSession($env['TELEGRAM_USER_SESSION']);
$u->mtproto->live();

$probe = function (string $label, callable $fn) use ($u): void {
    try {
        $res = $fn($u);
        echo "{$label} OK\n";
        if (is_string($res)) {
            echo '  ' . $res . "\n";
        }
    } catch (Throwable $e) {
        echo "{$label} FAIL: " . substr($e->getMessage(), 0, 180) . "\n";
    }
};

$probe('getHistory(self, 3)', function ($u) {
    $h = $u->call('messages.getHistory', [
        'peer' => ['_' => 'inputPeerSelf'], 'offset_id' => 0, 'offset_date' => 0,
        'add_offset' => 0, 'limit' => 3, 'max_id' => 0, 'min_id' => 0, 'hash' => 0,
    ]);
    $out = '  _=' . $h['_'] . ' count=' . ($h['count'] ?? '?');
    foreach (($h['messages'] ?? []) as $m) {
        $entities = count($m['entities'] ?? []);
        $from = $m['from_id']['user_id'] ?? ($m['peer_id']['user_id'] ?? '?');
        $out .= sprintf("\n  msg#%s from=%s text=\"%s\" entities=%d", $m['id'], $from, substr((string) ($m['message'] ?? ''), 0, 50), $entities);
    }
    return $out;
});

$probe('searchContacts(madeline)', function ($u) {
    $r = $u->call('contacts.search', ['q' => 'madeline', 'limit' => 5]);
    return '  results=' . count($r['users'] ?? []);
});

$probe('readHistory(self)', fn ($u) => $u->call('messages.readHistory', ['peer' => ['_' => 'inputPeerSelf'], 'max_id' => 0])['_'] ?? '');

// The full pipeline: HTML -> EntityParser -> MTProto entities -> send -> verify -> delete
$sentId = null;
$probe('sendMessage(self, formatted via EntityParser)', function ($u) use (&$sentId) {
    $parsed = EntityParser::htmlToEntities('<b>Teleproto</b> live <i>formatted</i> <a href="https://telegram.org">link</a> ✅');
    $updates = $u->call('messages.sendMessage', [
        'peer' => ['_' => 'inputPeerSelf'],
        'message' => $parsed['text'],
        'entities' => $parsed['entities'],
        'random_id' => random_int(1, PHP_INT_MAX),
    ]);
    // responses: updateShortSentMessage (no id in Updates?) or updateNewMessage inside updates
    if (($updates['_'] ?? '') === 'updateShortSentMessage') {
        $sentId = $updates['id'] ?? null;
        return '  updateShortSentMessage id=' . ($sentId ?? '?') . ' entities_roundtrip=' . count($updates['entities'] ?? []);
    }
    foreach (($updates['updates'] ?? []) as $upd) {
        if (($upd['_'] ?? '') === 'updateNewMessage' && isset($upd['message']['id'])) {
            $sentId = $upd['message']['id'];
            break;
        }
    }
    return '  updates=' . ($updates['_'] ?? '?') . ' sentId=' . ($sentId ?? '?');
});

if ($sentId !== null) {
    $probe('deleteMessages(self, revoke)', function ($u) use ($sentId) {
        $r = $u->call('messages.deleteMessages', ['id' => [$sentId], 'revoke' => true]);
        return '  _=' . $r['_'] . ' pts_count=' . ($r['pts_count'] ?? '?');
    });
}
