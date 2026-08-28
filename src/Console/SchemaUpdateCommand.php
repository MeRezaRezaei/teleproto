<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Console;

use Illuminate\Console\Command;
use MeRezaRezaei\Teleproto\Schema\SchemaDiffer;

/**
 * Fetches fresh upstream sources (curl, manual/CI network step), then
 * regenerates both artifacts in place, then reports the diff against the
 * pre-update committed artifacts (captured in memory before overwriting).
 *
 * A failed fetch never aborts with committed sources damaged: curl writes
 * a temp file that is renamed over the destination only on success. The
 * command exits 0 when it completes (the report communicates what moved;
 * schema-sensitive tests catch regressions) and 1 only on failures that
 * stopped the update. Zero regex by repo spec.
 */
class SchemaUpdateCommand extends Command
{
    protected $signature = 'teleproto:schema-update';

    protected $description = 'Fetch upstream schema sources, regenerate artifacts, and report the diff vs the committed ones';

    /** @var list<array{url: string, dest: string}> */
    private const FETCHES = [
        ['url' => 'https://raw.githubusercontent.com/telegramdesktop/tdesktop/dev/Telegram/SourceFiles/mtproto/scheme/api.tl', 'dest' => 'schema/sources/api.tl'],
        ['url' => 'https://raw.githubusercontent.com/telegramdesktop/tdesktop/dev/Telegram/SourceFiles/mtproto/scheme/mtproto.tl', 'dest' => 'schema/sources/mtproto.tl'],
        ['url' => 'https://core.telegram.org/api/errors.json', 'dest' => 'schema/sources/errors.json'],
        ['url' => 'https://raw.githubusercontent.com/danog/MadelineProto/master/extracted.json', 'dest' => 'schema/sources/extracted.json'],
        ['url' => 'https://raw.githubusercontent.com/PaulSonOfLars/telegram-bot-api-spec/main/api.json', 'dest' => 'schema/sources/botapi-spec.json'],
    ];

    public function handle(): int
    {
        $root = dirname(__DIR__, 2);

        // 1) Capture the pre-update committed artifacts before anything is written.
        $oldMtproto = SchemaAuditCommand::loadArtifact("{$root}/schema/methods-mtproto.json");
        $oldBotapi = SchemaAuditCommand::loadArtifact("{$root}/schema/methods-botapi.json");

        // 2) Fetch fresh sources; abort (nothing regenerated) if any fetch fails.
        $failures = [];
        foreach (self::FETCHES as $fetch) {
            $dest = "{$root}/{$fetch['dest']}";
            $tmpDest = $dest . '.fetching';
            $result = SchemaAuditCommand::runProcess(['curl', '-fsSL', '--max-time', '120', $fetch['url'], '-o', $tmpDest], $root);
            if ($result['exit'] !== 0 || !file_exists($tmpDest)) {
                if (file_exists($tmpDest)) {
                    unlink($tmpDest);
                }
                $failures[] = [$fetch, $result];
                continue;
            }
            rename($tmpDest, $dest);
            $this->line('<info>fetched ' . $fetch['dest'] . '</info>');
        }
        if ($failures !== []) {
            $this->reportFetchFailures($failures);
            return 1;
        }

        // 3) Regenerate both artifacts in place.
        $failure = SchemaAuditCommand::regenerateTo("{$root}/schema");
        if ($failure !== null) {
            $this->line("<error>Schema regeneration failed: {$failure}</error>");
            return 1;
        }

        // 4) Report pre-update vs freshly regenerated, in memory.
        $newMtproto = SchemaAuditCommand::loadArtifact("{$root}/schema/methods-mtproto.json");
        $newBotapi = SchemaAuditCommand::loadArtifact("{$root}/schema/methods-botapi.json");
        $mtprotoDiff = SchemaDiffer::diff($oldMtproto, $newMtproto);
        $botapiDiff = SchemaDiffer::diff($oldBotapi, $newBotapi);

        $this->line(SchemaAuditCommand::buildReport($oldMtproto, $newMtproto, $oldBotapi, $newBotapi));

        if (!SchemaAuditCommand::anyDifference($mtprotoDiff, $botapiDiff)) {
            $this->line('<info>Sources updated; artifacts unchanged.</info>');
        } else {
            $this->line('<comment>Sources updated; artifacts regenerated with differences above — review and commit them.</comment>');
        }

        return 0;
    }

    /**
     * @param list<array{0: array{url: string, dest: string}, 1: array{exit: int, output: string}}> $failures
     */
    private function reportFetchFailures(array $failures): void
    {
        $lines = ['Schema update aborted — ' . count($failures) . ' source fetch(es) failed:'];
        foreach ($failures as [$fetch, $result]) {
            $lines[] = "  - {$fetch['dest']} (exit {$result['exit']})";
            $lines[] = "    manual command: curl -fsSL {$fetch['url']} -o {$fetch['dest']}";
            if ($result['output'] !== '') {
                $lines[] = '    ' . $result['output'];
            }
        }
        $this->line('<error>' . implode("\n", $lines) . '</error>');
    }
}
