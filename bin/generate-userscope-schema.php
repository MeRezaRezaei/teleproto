<?php

declare(strict_types=1);

// Generates src/MTProto/TL/Schema/UserScopeSchema.php — the constructor
// closure (requests + all reachable response/nested types) for the methods
// Teleproto's UserAccountScope documents, parsed from MadelineProto's
// layer-227 TL files (lines keep their official #ids; no crc guesswork).
//
// Usage: php bin/generate-userscope-schema.php

$tlFiles = [
    '/home/me/Documents/projects/MadelineProto/src/TL_telegram_v227.tl',
    '/home/me/Documents/projects/MadelineProto/src/TL_mtproto_v1.tl',
];

// Seed: every request method UserAccountScope/BotAccountScope exposes.
$seedRequests = [
    'users.getUsers', 'users.getFullUser',
    'updates.getState',
    'messages.getDialogs', 'messages.getHistory', 'messages.search',
    'messages.sendMessage', 'messages.sendMedia', 'messages.sendReaction',
    'messages.updatePinnedMessage', 'messages.unpinAllMessages',
    'messages.readHistory', 'messages.forwardMessages', 'messages.deleteMessages',
    'messages.setTyping',
    'contacts.getContacts', 'contacts.importContacts', 'contacts.search', 'contacts.deleteContacts',
    'account.updateProfile', 'account.updateUsername', 'account.checkUsername', 'account.updateStatus', 'account.getAuthorizations',
    'channels.joinChannel', 'channels.leaveChannel', 'channels.getFullChannel',
    'channels.createChannel', 'channels.inviteToChannel', 'channels.getParticipants',
    'help.getNearestDc', 'help.getConfig',
    // auth (already in TLRegistry SCHEMA; harmless duplicates are skipped)
    'auth.sendCode', 'auth.signIn', 'auth.checkPassword', 'auth.exportLoginToken',
    'auth.importLoginToken', 'auth.importBotAuthorization', 'auth.resendCode', 'auth.cancelCode',
    'account.getPassword',
];

// Types whose constructor sets balloon without being needed on our paths.
// The Update family (~150 variants) is not chased via Vector<Update>, but the
// update constructors method responses actually carry are force-included below.
$typeBlocklist = [
    'Update', 'Page', 'RichText', 'PageBlock', 'TextQuote', 'TableCell', 'TableRow',
    'IPPort', 'StatsGraph', 'StatsAbsValueAndPrev', 'BroadcastStatsAbsValueAndPrev',
];

// Update constructors that routinely appear inside updates/updatesCombined
// responses of the seeded request methods.
$forceInclude = [
    'updateMessageID', 'updateNewMessage', 'updateEditMessage',
    'updateReadHistoryInbox', 'updateReadHistoryOutbox',
    'updateReadMessagesContents', 'updateDeleteMessages', 'updateSentMessage',
];

$lines = [];
$requests = [];
foreach ($tlFiles as $f) {
    $raw = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$raw) {
        fwrite(STDERR, "cannot read $f\n");
        exit(1);
    }
    $inFunctions = false;
    foreach ($raw as $line) {
        if (str_starts_with($line, '---functions---')) {
            $inFunctions = true;
            continue;
        }
        $line = trim($line, " \t;");
        if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '---')) {
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_.]+)#([0-9a-fA-F]+)\s/', $line, $m)) {
            if ($inFunctions) {
                $requests[$m[1]] = $line;
            } else {
                $lines[$m[1]] = $line; // constructor with explicit id
            }
        }
    }
}

// Map: type name -> list of constructor names (CONSTRUCTORS only; request
// methods never enter via type expansion — that would pull every method
// returning Bool whenever a Bool field appears).
$typeToCtors = [];
$ctorReturn = [];
foreach ($lines as $name => $line) {
    if (preg_match('/=\s*([A-Za-z0-9_.<> ]+)$/', $line, $m)) {
        $ret = trim($m[1]);
        if (preg_match('/^Vector[<(]\s*([A-Za-z0-9_.]+)\s*[>)]$/', $ret, $vm)) {
            $ret = $vm[1];
        }
        $ctorReturn[$name] = $ret;
        $typeToCtors[$ret][] = $name;
    }
}
$requestReturn = [];
foreach ($requests as $name => $line) {
    if (preg_match('/=\s*([A-Za-z0-9_.<> ]+)$/', $line, $m)) {
        $ret = trim($m[1]);
        if (preg_match('/^Vector[<(]\s*([A-Za-z0-9_.]+)\s*[>)]$/', $ret, $vm)) {
            $ret = $vm[1];
        }
        $requestReturn[$name] = $ret;
    }
}

// Field types referenced by a constructor line.
$fieldTypesOf = static function (string $line): array {
    $body = preg_replace('/^[A-Za-z0-9_.]+(#[0-9a-fA-F]+)?\s*/', '', $line);
    $body = trim(explode('=', (string)$body)[0]);
    $types = [];
    if ($body === '') {
        return $types;
    }
    foreach (explode(' ', $body) as $token) {
        if (!str_contains($token, ':')) {
            continue;
        }
        [, $t] = explode(':', $token, 2);
        $t = preg_replace('/^[a-zA-Z0-9_]+\.\d+\?/', '', $t); // strip flags.N?
        $t = preg_replace('/^#/', 'int', $t);
        $t = preg_replace('/^Vector[<(]\s*([A-Za-z0-9_.]+)\s*[>)]$/', '$1', (string)$t);
        if (preg_match('/^[A-Z][A-Za-z0-9_.]*$/', (string)$t)) {
            $types[] = (string)$t;
        }
    }
    return array_unique($types);
};

$include = [];
$queue = [];
foreach ($seedRequests as $r) {
    if (isset($requests[$r])) {
        $include[$r] = true;
        $queue = array_merge($queue, $fieldTypesOf($requests[$r]));
        $ret = $requestReturn[$r] ?? null;
        if ($ret !== null) {
            $queue[] = $ret;
        }
    } else {
        fwrite(STDERR, "WARN seed not found: $r\n");
    }
}

// Message entities (EntityParser output shapes)
foreach (['messageEntityBold','messageEntityItalic','messageEntityCode','messageEntityPre','messageEntityTextUrl','messageEntityUrl','messageEntityMention','messageEntityHashtag','messageEntityCashtag','messageEntityBotCommand','messageEntityEmail','messageEntityPhone','messageEntityUnderline','messageEntityStrike','messageEntitySpoiler','messageEntityCustomEmoji','messageEntityMentionName','messageEntityBlockquote','messageEntityExpandableBlockquote'] as $e) {
    if (isset($lines[$e])) {
        $include[$e] = true;
        $queue = array_merge($queue, $fieldTypesOf($lines[$e]));
    }
}

// Update constructors carried by method responses (see $typeBlocklist note)
foreach ($forceInclude as $name) {
    if (isset($lines[$name]) && !isset($include[$name])) {
        $include[$name] = true;
        $queue = array_merge($queue, $fieldTypesOf($lines[$name]));
    }
}
$seenTypes = [];
while ($queue) {
    $type = array_shift($queue);
    if ($type === '' || $type === 'Vector' || isset($seenTypes[$type]) || in_array($type, $typeBlocklist, true)) {
        continue;
    }
    $seenTypes[$type] = true;
    foreach ($typeToCtors[$type] ?? [] as $ctor) {
        if (isset($include[$ctor])) {
            continue;
        }
        $include[$ctor] = true;
        foreach ($fieldTypesOf($lines[$ctor]) as $ft) {
            if (!isset($seenTypes[$ft])) {
                $queue[] = $ft;
            }
        }
    }
}

ksort($include);
$count = count($include);

$out = "<?php\n\ndeclare(strict_types=1);\n\nnamespace MeRezaRezaei\\Teleproto\\MTProto\\TL\\Schema;\n\n/**\n * Constructor closure for Teleproto's documented scope methods (layer 227).\n * GENERATED from MadelineProto's layer-227 TL files — lines keep their\n * official #ids verbatim. Regenerate via bin/generate-userscope-schema.php.\n *\n * @generated 2026-08-28\n */\nfinal class UserScopeSchema\n{\n    /** @var list<string> canonical TL lines with official constructor ids */\n    public const LINES = [\n";
foreach (array_keys($include) as $name) {
    $src = $requests[$name] ?? $lines[$name] ?? null;
    if ($src === null) {
        continue;
    }
    $line = str_replace(['\\', "'"], ['\\\\', "\\'"], $src);
    $out .= "        '" . $line . "',\n";
}
$out .= "    ];\n}\n";

file_put_contents(__DIR__ . '/../src/MTProto/TL/Schema/UserScopeSchema.php', $out);
printf("written: %d constructors, %.1f KB\n", $count, filesize(__DIR__ . '/../src/MTProto/TL/Schema/UserScopeSchema.php') / 1024);
