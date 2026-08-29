<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Console;

use Illuminate\Console\Command;
use MeRezaRezaei\Teleproto\Schema\SchemaDiffer;

/**
 * Regenerates both schema artifacts into a temp dir from the committed
 * sources (offline) and diffs them against the committed artifacts.
 *
 * Exit 0 = committed artifacts are up to date; exit 1 = any difference
 * (added/removed/changed methods or a layer bump). Logic lives in
 * SchemaDiffer (tested) and the generators (tested); this class only
 * orchestrates processes and I/O. Zero regex by repo spec.
 */
class SchemaAuditCommand extends Command
{
    protected $signature = 'teleproto:schema-audit
                            {--write : Save the markdown report to schema/audit-report.md}';

    protected $description = 'Diff committed method schemas against a fresh regeneration from the committed sources';

    public function handle(): int
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir() . '/teleproto-schema-audit-' . bin2hex(random_bytes(6));

        $failure = self::regenerateTo($tmp);
        if ($failure !== null) {
            $this->line("<error>Schema regeneration failed: {$failure}</error>");
            self::cleanup($tmp);
            return 1;
        }

        $oldMtproto = self::loadArtifact("{$root}/schema/methods-mtproto.json");
        $newMtproto = self::loadArtifact("{$tmp}/methods-mtproto.json");
        $oldBotapi = self::loadArtifact("{$root}/schema/methods-botapi.json");
        $newBotapi = self::loadArtifact("{$tmp}/methods-botapi.json");

        $report = self::buildReport($oldMtproto, $newMtproto, $oldBotapi, $newBotapi);
        $this->line($report);
        self::cleanup($tmp);

        if ($this->option('write')) {
            file_put_contents("{$root}/schema/audit-report.md", $report . "\n");
            $this->line('<info>Report written to schema/audit-report.md</info>');
        }

        return self::anyDifference(
            SchemaDiffer::diff($oldMtproto, $newMtproto),
            SchemaDiffer::diff($oldBotapi, $newBotapi),
        ) ? 1 : 0;
    }

    /**
     * Run both generators writing into $outDir (sources stay the committed
     * repo ones). Returns null on success, a failure description otherwise.
     */
    public static function regenerateTo(string $outDir): ?string
    {
        $root = dirname(__DIR__, 2);
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            return "cannot create output dir {$outDir}";
        }
        foreach (['generate-method-schema.php', 'generate-botapi-schema.php'] as $generator) {
            $result = self::runProcess([PHP_BINARY, "{$root}/bin/{$generator}", $outDir], $root);
            if ($result['exit'] !== 0) {
                return "php bin/{$generator} exited {$result['exit']}: {$result['output']}";
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $oldMtproto
     * @param array<string, mixed> $newMtproto
     * @param array<string, mixed> $oldBotapi
     * @param array<string, mixed> $newBotapi
     */
    public static function buildReport(array $oldMtproto, array $newMtproto, array $oldBotapi, array $newBotapi): string
    {
        return implode("\n\n", [
            '# Schema audit report — ' . date('Y-m-d H:i:s T'),
            SchemaDiffer::reportSection('mtproto', $oldMtproto, $newMtproto),
            SchemaDiffer::reportSection('bot-http', $oldBotapi, $newBotapi),
        ]) . "\n";
    }

    /**
     * True when any diff reports added/removed/changed methods or a layer bump.
     *
     * @param array{added: list<string>, removed: list<string>, changed: list<string>, layer: int|null} ...$diffs
     */
    public static function anyDifference(array ...$diffs): bool
    {
        foreach ($diffs as $d) {
            if ($d['added'] !== [] || $d['removed'] !== [] || $d['changed'] !== [] || $d['layer'] !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadArtifact(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("cannot decode artifact {$path}");
        }
        return $decoded;
    }

    /**
     * Dev-only subprocess orchestration: spawns the bin/ generators
     * (php bin/generate-*.php). Exempted from the bin-only proc_open
     * policy in phpstan.neon.dist — see the comment there.
     *
     * @param list<string> $command
     * @return array{exit: int, output: string}
     */
    public static function runProcess(array $command, ?string $cwd = null): array
    {
        $proc = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );
        if (!is_resource($proc)) {
            return ['exit' => -1, 'output' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = (int) proc_close($proc);
        return ['exit' => $exit, 'output' => trim($output)];
    }

    public static function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (['methods-mtproto.json', 'methods-botapi.json'] as $file) {
            $path = "{$dir}/{$file}";
            if (file_exists($path)) {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
