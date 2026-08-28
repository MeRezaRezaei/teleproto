<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Console;

use Illuminate\Console\Command;
use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Services\TeleprotoAuthService;
use Throwable;

/**
 * Live MTProto health check: TCP + auth-key handshake + help.getNearestDc
 * (optionally bot MTProto login). Requires no Telegram account.
 */
class DoctorCommand extends Command
{
    protected $signature = 'teleproto:doctor
                            {--bot : Also verify bot MTProto authorization}
                            {--dc=2 : Target Telegram Data Center ID (1-5)}';

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

    /**
     * Must stay runnable on a bare command (no Laravel app): uses only
     * line()/error(), never $this->components or $this->option().
     */
    public function probeConnectivity(Client $client, string $host, int $port): int
    {
        $t0 = microtime(true);
        try {
            $result = $client->callToHost($host, $port);
            $ms = (int)((microtime(true) - $t0) * 1000);
            $this->line("<info>OK handshake+getNearestDc {$host}:{$port} in {$ms}ms</info>");
            if (isset($result['this_dc'], $result['nearest_dc'])) {
                $this->line("<info>This DC {$result['this_dc']}, nearest DC {$result['nearest_dc']}</info>");
            }
            return 0;
        } catch (Throwable $e) {
            $this->line("<error>FAIL {$host}:{$port} — " . $e->getMessage() . '</error>');
            return 1;
        }
    }

    protected function probeBotAuth(int $apiId, string $apiHash, string $token, int $dcId): int
    {
        try {
            $auth = app(TeleprotoAuthService::class);
            $auth->loginBot($token, $apiId, $apiHash, $dcId);
            $this->components->info('OK bot MTProto authorization (session generated)');
            return 0;
        } catch (Throwable $e) {
            $this->components->error('Bot MTProto login failed — ' . $e->getMessage());
            return 1;
        }
    }
}
